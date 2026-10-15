<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// ?scope=active is what the live screens poll. The old newest-50 page dropped
// an open order once 50 newer orders existed.
class OrderListScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'UserName' => ucfirst($role).' Person', 'Email' => $role.uniqid().'@example.com', 'Role' => $role,
            'Contact' => '09123456789', 'PasswordHash' => Hash::make('secret123'), 'Address' => 'PNR Main',
        ]);
    }

    private function order(User $customer, string $status, string $placed, ?string $changed = null, array $extra = []): Order
    {
        return Order::create([
            'UserID' => $customer->UserID, 'TotalPrice' => 100, 'ServiceFee' => 75, 'DeliveryStatus' => $status,
            'DeliveryAddress' => 'PNR Main', 'OrderDate' => now()->sub($placed),
            'StatusUpdatedAt' => $changed ? now()->sub($changed) : null,
        ] + $extra);
    }

    private function bill(Order $order, string $paymentStatus): void
    {
        Payment::create([
            'OrderID' => $order->OrderID, 'PaymentName' => 'NEECO 1', 'PaymentMethod' => 'gcash',
            'PaymentAmount' => 500, 'PaymentStatus' => $paymentStatus, 'PaymentNote' => '{}',
        ]);
    }

    private function ids(string $token, string $url): array
    {
        $this->app['auth']->forgetGuards();
        return collect($this->withToken($token)->getJson($url)->assertOk()->json('data'))->pluck('OrderID')->all();
    }

    public function test_admin_live_list_keeps_old_open_orders_and_drops_old_finished_ones(): void
    {
        $customer = $this->user('customer');
        $oldPending = $this->order($customer, 'pending_rider', '3 days');
        $oldFinished = $this->order($customer, 'delivered', '3 days', '3 days');
        $oldReported = $this->order($customer, 'delivered', '3 days', '3 days', ['ReportedAt' => now()->subDays(2), 'ReportReason' => 'Late']);
        $oldUnreviewedBill = $this->order($customer, 'delivered', '3 days', '3 days');
        $this->bill($oldUnreviewedBill, 'pending');
        $oldReviewedBill = $this->order($customer, 'delivered', '3 days', '3 days');
        $this->bill($oldReviewedBill, 'verified');
        $oldCancelledBill = $this->order($customer, 'cancelled', '3 days', '3 days');
        $this->bill($oldCancelledBill, 'pending');
        $recent = collect(range(1, 60))->map(fn ($n) => $this->order($customer, 'delivered', "{$n} minutes", "{$n} minutes")->OrderID);

        $admin = $this->user('admin')->createToken('t')->plainTextToken;
        $active = $this->ids($admin, '/api/admin/orders?scope=active');

        $this->assertSame($oldPending->OrderID, $active[0], 'open orders come first');
        $this->assertEqualsCanonicalizing([$oldPending->OrderID, $oldReported->OrderID, $oldUnreviewedBill->OrderID, ...$recent],
            $active);
        $this->assertNotContains($oldFinished->OrderID, $active);
        $this->assertNotContains($oldReviewedBill->OrderID, $active);
        $this->assertNotContains($oldCancelledBill->OrderID, $active);

        // The plain list (History) is unchanged: newest first, at most 50.
        $plain = $this->ids($admin, '/api/admin/orders?per_page=500');
        $this->assertCount(50, $plain);
        $this->assertNotContains($oldPending->OrderID, $plain);
    }

    public function test_customer_and_rider_live_lists_keep_their_old_open_orders(): void
    {
        $customer = $this->user('customer');
        $rider = $this->user('driver');
        $oldPending = $this->order($customer, 'pending_rider', '2 days');
        $oldAssigned = $this->order($customer, 'out_for_delivery', '2 days', '2 days', ['AssignedRiderID' => $rider->UserID]);
        foreach (range(1, 55) as $n) {
            $this->order($customer, 'delivered', "{$n} minutes", "{$n} minutes", ['AssignedRiderID' => $rider->UserID]);
        }
        $someoneElse = $this->order($this->user('customer'), 'pending_rider', '1 minute');

        $customerList = $this->ids($customer->createToken('t')->plainTextToken, '/api/orders?scope=active');
        $this->assertContains($oldPending->OrderID, $customerList);
        $this->assertContains($oldAssigned->OrderID, $customerList);
        $this->assertNotContains($someoneElse->OrderID, $customerList);
        $this->assertCount(57, $customerList);

        $riderList = $this->ids($rider->createToken('t')->plainTextToken, '/api/orders?scope=active');
        $this->assertSame($oldAssigned->OrderID, $riderList[0]);
        $this->assertNotContains($oldPending->OrderID, $riderList);
        $this->assertCount(56, $riderList);
    }
}
