<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Support\ClientOrder;
use App\Support\DeliveryFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    // Creates the Orders + Payment pair backing a Pay Bills request. Bill
    // payments have no catalog Product to line up with OrderController::store,
    // so they get their own endpoint instead of reusing that one - the
    // resulting Order still flows through the normal rider/admin status
    // pipeline (a rider still has to go pay the bill in person), it just
    // starts with zero OrderItems.
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->Role === 'customer', 403);
        // Receipt/proof links must be documents this same customer uploaded
        // through /api/uploads/bill-documents in the last day (the route records
        // the owner) - not an outside site, a javascript: URL, or someone
        // else's file whose name leaked.
        $uploadedDocument = function ($attribute, $value, $fail) use ($request) {
            $valid = preg_match('#^/uploads/bill-documents/([0-9a-f-]{36}\.(?:jpg|jpeg|png|webp|gif|pdf))$#', (string) $value, $match) === 1
                && Storage::disk('local')->exists('bill-documents/'.$match[1])
                && Cache::get('bill-document-owner:'.$match[1]) === $request->user()->UserID;
            if (!$valid) $fail('Please upload the bill documents again.');
        };
        $data = $request->validate([
            'establishment' => ['required', 'string', 'max:150'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'method' => ['nullable', 'string', 'max:50'],
            'billReceiptUrl' => ['bail', 'nullable', 'string', 'max:500', $uploadedDocument],
            'billReceiptName' => ['nullable', 'string', 'max:255'],
            'transferProofUrl' => ['bail', 'nullable', 'string', 'max:500', $uploadedDocument],
            'transferProofName' => ['nullable', 'string', 'max:255'],
            // Ignored (still accepted from older clients): the server sets the fee.
            'serviceFee' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'deliveryAddress' => ['bail', 'required', 'string', 'max:2000', function ($attribute, $value, $fail) {
                if (!DeliveryFee::zoneFor($value)) $fail('Delivery address must be within a supported delivery location.');
            }],
            'clientOrderId' => ['nullable', 'string', 'max:100'],
            'billReceiptType' => ['nullable', 'string', 'max:100'],
            'transferProofType' => ['nullable', 'string', 'max:100'],
        ]);

        // Same double-submit guard as OrderController::store, scoped to this customer.
        return ClientOrder::once($request, $data['clientOrderId'] ?? null, function () use ($data, $request) {
            $existing = ClientOrder::recent($request, $data['clientOrderId'] ?? null)?->payments()->first();
            if ($existing) return response()->json(['payment' => $existing, 'orderId' => $existing->OrderID], 200);
            return $this->createPayment($data, $request);
        });
    }

    private function createPayment(array $data, Request $request): JsonResponse
    {
        $payment = DB::transaction(function () use ($data, $request) {
            $serviceFee = DeliveryFee::for($request->user()->UserType);
            $order = Order::create([
                'UserID' => $request->user()->UserID,
                'TotalPrice' => ($data['amount'] ?? 0) + $serviceFee,
                'ServiceFee' => $serviceFee,
                'OrderDate' => now(),
                'DeliveryAddress' => $data['deliveryAddress'] ?? null,
                'OrderSnapshot' => ['section' => 'bills', 'clientOrderId' => $data['clientOrderId'] ?? null],
                'DeliveryStatus' => 'pending_rider',
            ]);

            // Payment has no dedicated columns for the uploaded proof files,
            // so they ride along as JSON in PaymentNote rather than adding a
            // migration on top of the team's predetermined schema.
            return $order->payments()->create([
                'PaymentName' => $data['establishment'],
                'PaymentMethod' => $data['method'] ?? null,
                'PaymentAmount' => $data['amount'] ?? 0,
                'PaymentStatus' => 'pending',
                'PaymentNote' => json_encode([
                    'billReceiptUrl' => $data['billReceiptUrl'] ?? null,
                    'billReceiptName' => $data['billReceiptName'] ?? null,
                    'transferProofUrl' => $data['transferProofUrl'] ?? null,
                    'transferProofName' => $data['transferProofName'] ?? null,
                    'billReceiptType' => $data['billReceiptType'] ?? null,
                    'transferProofType' => $data['transferProofType'] ?? null,
                ]),
            ]);
        });

        return response()->json(['payment' => $payment->fresh(), 'orderId' => $payment->OrderID], 201);
    }

    public function updateStatus(Request $request, Payment $payment): JsonResponse
    {
        abort_if(in_array($payment->PaymentStatus, ['verified', 'rejected'], true), 422, 'This payment is already finalized.');
        abort_if($payment->order->DeliveryStatus === 'cancelled', 422, 'This order was cancelled; its payment cannot be reviewed.');

        $data = $request->validate([
            'status' => ['required', Rule::in(['verified', 'rejected'])],
        ]);

        $payment->update(['PaymentStatus' => $data['status']]);
        // Same reasoning as OrderController::notifyStatusChange - admin
        // verifies/rejects from their own console, the customer needs to
        // find out on whichever device they check next.
        Notification::create([
            'UserID' => $payment->order->UserID,
            'NotificationMessage' => json_encode([
                'title' => "Payment {$data['status']}",
                'message' => "Your payment for {$payment->PaymentName} was {$data['status']}.",
                'type' => $data['status'] === 'rejected' ? 'cancelled' : 'status',
                'orderId' => $payment->OrderID,
            ]),
            'NotificationSeen' => false,
            'NotificationDate' => now(),
        ]);
        return response()->json(['payment' => $payment->fresh()]);
    }
}
