<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeliveryEstimateApiTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 02:00:00', 'UTC'));
        $this->customer = User::create([
            'UserName' => 'Customer', 'Email' => 'estimate.customer@example.com', 'Role' => 'customer',
            'Contact' => '09123456789', 'PasswordHash' => Hash::make('secret123'),
        ]);
    }

    // A past order that took $minutes from placement to delivery.
    private function delivered(int $minutes, int $daysAgo = 1): void
    {
        $placed = now()->subDays($daysAgo);
        Order::create([
            'UserID' => $this->customer->UserID, 'TotalPrice' => 100, 'ServiceFee' => 75,
            'DeliveryAddress' => 'PNR Main', 'DeliveryStatus' => 'delivered',
            'OrderDate' => $placed, 'StatusUpdatedAt' => $placed->copy()->addMinutes($minutes),
        ]);
    }

    private function waitingOrder(int $quantity = 1): Order
    {
        return Order::create([
            'UserID' => $this->customer->UserID, 'TotalPrice' => 100, 'ServiceFee' => 75,
            'DeliveryAddress' => 'PNR Main', 'DeliveryStatus' => 'pending_rider', 'OrderDate' => now(),
            'OrderSnapshot' => ['items' => [['name' => 'Siomai', 'quantity' => $quantity]]],
        ]);
    }

    private function estimateFor(Order $order): array
    {
        $orders = $this->withToken($this->customer->createToken('t')->plainTextToken)->getJson('/api/orders')->assertOk()->json('data');
        return collect($orders)->firstWhere('OrderID', $order->OrderID);
    }

    public function test_without_history_it_falls_back_to_the_item_formula(): void
    {
        // 3 items: 40 + 2 * 2 = 44 minutes.
        $estimate = $this->estimateFor($this->waitingOrder(3))['estimatedMinutes'];

        $this->assertSame(['min' => 39, 'max' => 49, 'basis' => 'formula'], array_intersect_key($estimate, array_flip(['min', 'max', 'basis'])));
    }

    public function test_outliers_are_ignored_and_too_few_usable_orders_still_fall_back(): void
    {
        foreach ([30, 30, 30, 30] as $minutes) $this->delivered($minutes);
        foreach ([2, 3, 300, 900] as $minutes) $this->delivered($minutes);

        $estimate = $this->estimateFor($this->waitingOrder(1))['estimatedMinutes'];

        // Only 4 usable durations - not enough - so the formula (40) applies.
        $this->assertSame('formula', $estimate['basis']);
        $this->assertSame([35, 45], [$estimate['min'], $estimate['max']]);
    }

    public function test_with_enough_history_it_uses_the_median_of_recent_deliveries(): void
    {
        foreach ([20, 30, 40, 50, 60] as $minutes) $this->delivered($minutes);
        foreach ([1, 4, 181, 600] as $minutes) $this->delivered($minutes);

        $estimate = $this->estimateFor($this->waitingOrder(5))['estimatedMinutes'];

        // Median of 20/30/40/50/60 is 40, whatever the item count.
        $this->assertSame('recent_orders', $estimate['basis']);
        $this->assertSame([35, 45], [$estimate['min'], $estimate['max']]);
    }

    public function test_only_the_last_20_deliveries_count(): void
    {
        foreach (range(1, 5) as $_) $this->delivered(120, daysAgo: 10);
        foreach (range(1, 20) as $_) $this->delivered(25, daysAgo: 1);

        $estimate = $this->estimateFor($this->waitingOrder())['estimatedMinutes'];

        $this->assertSame([20, 30], [$estimate['min'], $estimate['max']]);
    }

    public function test_orders_ahead_and_the_countdown_target_come_with_the_estimate(): void
    {
        $first = $this->waitingOrder();
        Carbon::setTestNow(now()->addMinute());
        $second = $this->waitingOrder();

        $this->assertSame(0, $this->estimateFor($first)['estimatedMinutes']['ordersAhead']);
        $this->app['auth']->forgetGuards();
        $mine = $this->estimateFor($second);
        $this->assertSame(1, $mine['estimatedMinutes']['ordersAhead']);
        $this->assertSame(2, $mine['queuePosition']);
        // Countdown target: placement time plus the top of the range (45 min).
        $this->assertSame(Carbon::parse($second->OrderDate)->addMinutes(45)->format('Y-m-d H:i:s'), $mine['estimatedCompletionAt']);
    }
}
