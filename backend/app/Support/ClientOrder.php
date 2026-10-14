<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Double-submit guard shared by OrderController::store and
// PaymentController::store: the same clientOrderId from the same customer
// returns the order already created instead of inserting a second one.
class ClientOrder
{
    // The duplicate check and the insert run under a per-customer lock, so two
    // requests with the same key on different server workers can't both see
    // "no order yet" and both insert.
    public static function once(Request $request, ?string $clientOrderId, \Closure $callback): JsonResponse
    {
        if (empty($clientOrderId)) return $callback();
        try {
            return Cache::lock('client-order:'.$request->user()->UserID.':'.sha1($clientOrderId), 15)->block(10, $callback);
        } catch (LockTimeoutException) {
            return response()->json(['error' => 'This order is still being placed. Please wait a moment.'], 409);
        }
    }

    // Only the last hour counts: a retry comes within seconds, while the
    // browser's short order numbers (last 7 digits of a timestamp) repeat
    // every ~2.8 hours and must not match an unrelated older order.
    public static function recent(Request $request, ?string $clientOrderId): ?Order
    {
        if (empty($clientOrderId)) return null;
        return Order::where('UserID', $request->user()->UserID)
            ->where('OrderDate', '>=', now()->subHour())
            ->whereJsonContains('OrderSnapshot->clientOrderId', $clientOrderId)
            ->first();
    }
}
