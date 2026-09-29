<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email = null): User
    {
        return User::create([
            'UserName' => ucfirst($role), 'Email' => $email ?? $role.'@example.com', 'Role' => $role,
            'Contact' => '09123456789', 'PasswordHash' => Hash::make('secret123'),
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_customer_can_submit_a_bill_payment_and_it_creates_an_order_and_payment(): void
    {
        $customer = $this->user('customer');

        $response = $this->withToken($this->token($customer))->postJson('/api/payments', [
            'establishment' => 'NEECO 2',
            'amount' => 850,
            'method' => 'GCash',
            'billReceiptUrl' => '/uploads/bill.jpg',
            'transferProofUrl' => '/uploads/proof.jpg',
        ])->assertCreated();

        $orderId = $response->json('orderId');
        $this->assertDatabaseHas('Orders', [
            'OrderID' => $orderId, 'UserID' => $customer->UserID, 'TotalPrice' => 850,
            'DeliveryStatus' => 'pending_rider',
        ]);
        $this->assertDatabaseHas('Payment', [
            'OrderID' => $orderId, 'PaymentName' => 'NEECO 2', 'PaymentAmount' => 850,
            'PaymentStatus' => 'pending',
        ]);
    }

    public function test_payment_persists_service_fee_and_adds_it_to_the_order_total(): void
    {
        $customer = $this->user('customer');

        $response = $this->withToken($this->token($customer))->postJson('/api/payments', [
            'establishment' => 'PrimeWater', 'amount' => 500, 'serviceFee' => 75,
        ])->assertCreated();

        $this->assertDatabaseHas('Orders', [
            'OrderID' => $response->json('orderId'), 'TotalPrice' => 575, 'ServiceFee' => 75,
        ]);
    }

    public function test_payment_rejects_a_service_fee_outside_the_sane_range(): void
    {
        $customer = $this->user('customer');

        $this->withToken($this->token($customer))->postJson('/api/payments', [
            'establishment' => 'NEECO 2', 'amount' => 500, 'serviceFee' => 501,
        ])->assertStatus(422);
    }

    public function test_bill_fee_reaches_revenue_and_completed_history_across_pages(): void
    {
        $customer = $this->user('customer');
        $admin = $this->user('admin');
        $bill = $this->withToken($this->token($customer))->postJson('/api/payments', [
            'establishment' => 'PrimeWater', 'amount' => 500, 'serviceFee' => 75,
            'deliveryAddress' => 'CLSU Main Campus', 'clientOrderId' => 'BILL-TEST',
            'billReceiptUrl' => '/uploads/bill-documents/bill.pdf',
            'billReceiptType' => 'application/pdf',
        ])->assertCreated();
        $order = \App\Models\Order::findOrFail($bill->json('orderId'));
        $order->update(['OrderDate' => now()->subDay()]);
        $this->assertSame('CLSU Main Campus', $order->DeliveryAddress);
        $this->assertSame('BILL-TEST', $order->OrderSnapshot['clientOrderId']);

        // A full page of newer non-bill orders must not hide bill history.
        for ($i = 0; $i < 21; $i++) {
            \App\Models\Order::create(['UserID' => $customer->UserID, 'TotalPrice' => 0,
                'ServiceFee' => 0, 'OrderDate' => now(), 'DeliveryStatus' => 'delivered']);
        }
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($admin));
        $this->patchJson('/api/payments/'.$bill->json('payment.PaymentID').'/status', ['status' => 'verified'])->assertOk();
        $this->patchJson('/api/orders/'.$order->OrderID.'/status', ['status' => 'delivered'])->assertOk();
        $revenue = $this->getJson('/api/admin/revenue')->assertOk();
        $this->assertEquals(75, $revenue->json('total'));
        $this->assertEquals(75, $revenue->json('byService.bills'));
        $history = $this->getJson('/api/admin/orders?status=delivered,cancelled&service=bills&per_page=20')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.OrderID', $order->OrderID);
        $this->assertEquals(575, $history->json('data.0.TotalPrice'));
        $note = json_decode($history->json('data.0.payments.0.PaymentNote'), true);
        $this->assertSame('application/pdf', $note['billReceiptType']);
    }

    public function test_payment_rejects_an_amount_outside_the_sane_range(): void
    {
        $customer = $this->user('customer');

        $this->withToken($this->token($customer))->postJson('/api/payments', [
            'establishment' => 'NEECO 2', 'amount' => 1000000,
        ])->assertStatus(422);
    }

    public function test_only_customers_can_submit_payments(): void
    {
        $driver = $this->user('driver');
        $admin = $this->user('admin');

        $this->withToken($this->token($driver))->postJson('/api/payments', [
            'establishment' => 'NEECO 2', 'amount' => 500,
        ])->assertStatus(403);

        $this->withToken($this->token($admin))->postJson('/api/payments', [
            'establishment' => 'NEECO 2', 'amount' => 500,
        ])->assertStatus(403);
    }

    private function pendingPayment(User $customer): Payment
    {
        $order = \App\Models\Order::create([
            'UserID' => $customer->UserID, 'TotalPrice' => 500, 'ServiceFee' => 0,
            'OrderDate' => now(), 'DeliveryStatus' => 'pending_rider',
        ]);
        return $order->payments()->create([
            'PaymentName' => 'NEECO 2', 'PaymentAmount' => 500, 'PaymentStatus' => 'pending',
        ]);
    }

    public function test_only_admin_can_update_payment_status(): void
    {
        $customer = $this->user('customer');
        $driver = $this->user('driver');
        $payment = $this->pendingPayment($customer);

        $this->withToken($this->token($customer))->patchJson("/api/payments/{$payment->PaymentID}/status", [
            'status' => 'verified',
        ])->assertStatus(403);

        $this->withToken($this->token($driver))->patchJson("/api/payments/{$payment->PaymentID}/status", [
            'status' => 'verified',
        ])->assertStatus(403);

        $this->assertDatabaseHas('Payment', ['PaymentID' => $payment->PaymentID, 'PaymentStatus' => 'pending']);
    }

    public function test_admin_can_verify_a_payment_and_it_notifies_the_customer(): void
    {
        $customer = $this->user('customer');
        $admin = $this->user('admin');
        $payment = $this->pendingPayment($customer);

        $this->withToken($this->token($admin))->patchJson("/api/payments/{$payment->PaymentID}/status", [
            'status' => 'verified',
        ])->assertOk()->assertJsonPath('payment.PaymentStatus', 'verified');

        $this->assertDatabaseHas('Payment', ['PaymentID' => $payment->PaymentID, 'PaymentStatus' => 'verified']);
        $this->assertDatabaseHas('Notification', ['UserID' => $customer->UserID]);
    }

    public function test_admin_can_reject_a_payment_and_it_notifies_the_customer(): void
    {
        $customer = $this->user('customer');
        $admin = $this->user('admin');
        $payment = $this->pendingPayment($customer);

        $this->withToken($this->token($admin))->patchJson("/api/payments/{$payment->PaymentID}/status", [
            'status' => 'rejected',
        ])->assertOk()->assertJsonPath('payment.PaymentStatus', 'rejected');

        $this->assertDatabaseHas('Payment', ['PaymentID' => $payment->PaymentID, 'PaymentStatus' => 'rejected']);
    }

    public function test_finalized_payment_status_cannot_be_changed_again(): void
    {
        $customer = $this->user('customer');
        $admin = $this->user('admin');
        $payment = $this->pendingPayment($customer);
        $payment->update(['PaymentStatus' => 'verified']);

        $this->withToken($this->token($admin))->patchJson("/api/payments/{$payment->PaymentID}/status", [
            'status' => 'rejected',
        ])->assertStatus(422);

        $this->assertDatabaseHas('Payment', ['PaymentID' => $payment->PaymentID, 'PaymentStatus' => 'verified']);
    }
}
