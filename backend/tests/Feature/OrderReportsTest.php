<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Revenue by service and the History/Payments filters run as SQL
// (App\Support\OrderSql) rather than over orders loaded into PHP.
class OrderReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = $this->user('customer', 'Maria Santos', 'maria.santos@example.com', '09171234567');
        $this->admin = $this->user('admin', 'Admin', 'admin@example.com', '09170000000')->createToken('t')->plainTextToken;
    }

    private function user(string $role, string $name, string $email, string $contact): User
    {
        return User::create([
            'UserName' => $name, 'Email' => $email, 'Role' => $role, 'Contact' => $contact,
            'PasswordHash' => Hash::make('secret123'), 'Address' => 'PNR Main',
        ]);
    }

    private function catalogOrder(string $serviceType, float $fee, ?User $customer = null): Order
    {
        $service = Service::create(['ServiceName' => ucfirst($serviceType).' '.uniqid(), 'ServiceType' => $serviceType, 'IsActive' => true]);
        $brand = Brand::create(['BrandName' => 'Brand '.uniqid(), 'ServiceID' => $service->ServiceID, 'IsActive' => true]);
        $product = Product::create(['ProductName' => 'Thing', 'BrandID' => $brand->BrandID, 'ProductPrice' => 100, 'IsActive' => true]);
        $order = $this->order($fee, 'delivered', ['clientOrderId' => null], $customer);
        OrderItem::create(['OrderID' => $order->OrderID, 'ProductID' => $product->ProductID, 'OrderItemPrice' => 100, 'ProductQuantity' => 1]);
        return $order;
    }

    private function order(float $fee, string $status = 'delivered', array $snapshot = [], ?User $customer = null): Order
    {
        return Order::create([
            'UserID' => ($customer ?? $this->customer)->UserID, 'TotalPrice' => 100 + $fee, 'ServiceFee' => $fee,
            'DeliveryStatus' => $status, 'DeliveryAddress' => 'PNR Main', 'OrderSnapshot' => $snapshot,
        ]);
    }

    private function bill(float $fee, string $paymentStatus, string $status = 'delivered'): Order
    {
        $order = $this->order($fee, $status, ['section' => 'bills']);
        Payment::create([
            'OrderID' => $order->OrderID, 'PaymentName' => 'NEECO 1', 'PaymentMethod' => 'gcash',
            'PaymentAmount' => 500, 'PaymentStatus' => $paymentStatus, 'PaymentNote' => '{}',
        ]);
        return $order;
    }

    private function adminIds(string $query): array
    {
        return collect($this->withToken($this->admin)->getJson("/api/admin/orders?{$query}")->assertOk()->json('data'))
            ->pluck('OrderID')->sort()->values()->all();
    }

    public function test_revenue_splits_fees_by_service_in_the_database(): void
    {
        $this->catalogOrder('food', 10);
        $this->catalogOrder('item', 20);
        $this->order(40, 'delivered', ['section' => 'item']);  // custom "Others" item order
        $this->order(80, 'delivered', ['section' => 'food']);
        $this->bill(160, 'verified');
        $this->catalogOrder('item', 999)->update(['DeliveryStatus' => 'cancelled']);

        $response = $this->withToken($this->admin)->getJson('/api/admin/revenue')->assertOk();

        $this->assertEquals(['food' => 90, 'item' => 60, 'bills' => 160], $response->json('byService'));
        $this->assertEquals(310, $response->json('total'));
        $this->assertEquals(5, $response->json('orderCount'));
    }

    public function test_history_service_filter_runs_on_the_server(): void
    {
        $food = $this->catalogOrder('food', 10);
        $item = $this->catalogOrder('item', 10);
        $custom = $this->order(10, 'delivered', ['section' => 'item']);
        $bill = $this->bill(10, 'verified');

        $this->assertSame([$item->OrderID, $custom->OrderID], $this->adminIds('service=item'));
        $this->assertSame([$food->OrderID], $this->adminIds('service=food'));
        $this->assertSame([$bill->OrderID], $this->adminIds('service=bills'));
        $this->withToken($this->admin)->getJson('/api/admin/orders?service=anything')->assertUnprocessable();
    }

    public function test_history_search_matches_order_number_or_customer(): void
    {
        $other = $this->user('customer', 'Jose Rizal', 'jose@example.com', '09998887777');
        $maria = $this->order(10);
        $jose = $this->order(10, 'delivered', [], $other);

        $this->assertSame([$jose->OrderID], $this->adminIds('search='.$jose->OrderID));
        $this->assertSame([$jose->OrderID], $this->adminIds('search='.urlencode("Order #{$jose->OrderID}")));
        $this->assertSame([$jose->OrderID], $this->adminIds('search='.urlencode("#{$jose->OrderID}")));
        $this->assertSame([$maria->OrderID], $this->adminIds('search=santos'));
        $this->assertSame([$maria->OrderID], $this->adminIds('search=maria.santos%40'));
        $this->assertSame([$jose->OrderID], $this->adminIds('search=0999888'));
        $this->assertSame([], $this->adminIds('search=nobody'));
    }

    public function test_payments_filter_returns_only_bills_awaiting_review(): void
    {
        $pending = $this->bill(10, 'pending');
        $this->bill(10, 'verified');
        $this->bill(10, 'rejected');
        $this->bill(10, 'pending', 'cancelled');
        $this->order(10);

        $this->assertSame([$pending->OrderID], $this->adminIds('service=bills&payment=pending'));
        $this->assertCount(4, $this->adminIds('service=bills'));
    }
}
