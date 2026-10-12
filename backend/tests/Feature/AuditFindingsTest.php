<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Regression tests for the 2026-10-12 security/feature audit. Each test names
// the audit finding it covers (S = security, R = reliability). The "still
// works" group pins down behaviour the audit confirmed was already correct.
class AuditFindingsTest extends TestCase
{
    use RefreshDatabase;

    private const ZONES = ['CLSU Main Campus', 'Bagong Sikat', 'Villa Javier', 'Bukang Liwayway', 'Tobias Ville', 'Villa Isidra', 'PNR Main'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?string $email = null, array $extra = []): User
    {
        return User::create(array_merge([
            'UserName' => ucfirst($role).' Person', 'Email' => $email ?? $role.uniqid().'@example.com', 'Role' => $role,
            'Contact' => '09123456789', 'PasswordHash' => Hash::make('secret123'), 'Address' => $role.' home address',
        ], $extra));
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    // Each request in a test may come from a different user; without this the
    // guard keeps the first authenticated user for the whole test.
    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($token);
    }

    private function product(float $price = 100): Product
    {
        $brand = Brand::create(['BrandName' => 'Audit Brand '.uniqid(), 'IsActive' => true]);
        return Product::create(['ProductName' => 'Audit Item', 'BrandID' => $brand->BrandID, 'ProductPrice' => $price, 'IsActive' => true]);
    }

    private function order(User $customer, string $status = 'pending_rider', ?User $rider = null): Order
    {
        return Order::create([
            'UserID' => $customer->UserID, 'AssignedRiderID' => $rider?->UserID, 'TotalPrice' => 100,
            'ServiceFee' => 75, 'DeliveryStatus' => $status, 'DeliveryAddress' => 'PNR Main', 'OrderDate' => now(),
        ]);
    }

    private function setStatus(string $token, Order $order, string $status)
    {
        return $this->as($token)->patchJson("/api/orders/{$order->OrderID}/status", ['status' => $status]);
    }

    // Manila wall-clock time -> the UTC "now" the server sees.
    private function atManila(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-10-12 {$time}", 'Asia/Manila')->setTimezone('UTC'));
    }

    // ---------------------------------------------------------------- S1 / S2

    public function test_S1_profile_password_change_requires_the_current_password(): void
    {
        $customer = $this->user('customer', 'carol.audit@example.com');
        $token = $this->token($customer);
        $profile = ['username' => 'Carol', 'email' => 'carol.audit@example.com', 'address' => 'PNR Main'];

        $this->as($token)->patchJson('/api/auth/me', $profile + ['password' => 'NewSecret99'])->assertStatus(422);
        $this->as($token)->patchJson('/api/auth/me', $profile + ['password' => 'NewSecret99', 'currentPassword' => 'wrong-one1'])->assertStatus(422);
        $this->assertTrue(Hash::check('secret123', $customer->fresh()->PasswordHash));

        $this->as($token)->patchJson('/api/auth/me', $profile + ['password' => 'NewSecret99', 'currentPassword' => 'secret123'])->assertOk();
        $this->assertTrue(Hash::check('NewSecret99', $customer->fresh()->PasswordHash));
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $customer = $this->user('customer', 'gina.audit@example.com');
        $token = $this->token($customer);
        $profile = ['username' => 'Gina', 'email' => 'gina.changed@example.com', 'address' => 'PNR Main'];

        $this->as($token)->patchJson('/api/auth/me', $profile)->assertStatus(422);
        $this->as($token)->patchJson('/api/auth/me', $profile + ['currentPassword' => 'wrong-one1'])->assertStatus(422);
        $this->assertSame('gina.audit@example.com', $customer->fresh()->Email);

        // Keeping the same email needs no password; changing it with the right one works.
        $this->as($token)->patchJson('/api/auth/me', ['email' => 'gina.audit@example.com'] + $profile)->assertOk();
        $this->as($token)->patchJson('/api/auth/me', $profile + ['currentPassword' => 'secret123'])->assertOk();
        $this->assertSame('gina.changed@example.com', $customer->fresh()->Email);
    }

    public function test_S2_change_password_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        $customer = $this->user('customer');
        $current = $this->token($customer);
        $other = $this->token($customer);

        $this->as($current)->postJson('/api/auth/change-password', ['currentPassword' => 'secret123', 'password' => 'Changed123'])->assertOk();

        $this->as($other)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($current)->getJson('/api/auth/me')->assertOk();
    }

    public function test_S2_profile_password_change_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        $customer = $this->user('customer', 'dana.audit@example.com');
        $current = $this->token($customer);
        $other = $this->token($customer);

        $this->as($current)->patchJson('/api/auth/me', [
            'username' => 'Dana', 'email' => 'dana.audit@example.com', 'address' => 'PNR Main',
            'password' => 'Changed123', 'currentPassword' => 'secret123',
        ])->assertOk();

        $this->as($other)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($current)->getJson('/api/auth/me')->assertOk();
    }

    public function test_S2_password_reset_link_revokes_every_session(): void
    {
        $customer = $this->user('customer', 'erin.audit@example.com');
        $session = $this->token($customer);
        DB::table('password_reset_tokens')->insert(['email' => 'erin.audit@example.com', 'token' => hash('sha256', 'reset-token'), 'created_at' => now()]);

        $this->postJson('/api/auth/reset-password', ['email' => 'erin.audit@example.com', 'token' => 'reset-token', 'password' => 'Reset1234'])->assertOk();

        $this->as($session)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_S2_admin_password_reset_revokes_that_users_sessions(): void
    {
        $customer = $this->user('customer');
        $session = $this->token($customer);

        $this->as($this->token($this->user('admin')))->putJson("/api/admin/accounts/customer/{$customer->UserID}", ['password' => 'AdminSet123'])->assertOk();

        $this->as($session)->getJson('/api/auth/me')->assertUnauthorized();
    }

    // -------------------------------------------------------------------- S7

    public function test_S7_trivial_passwords_are_rejected_everywhere_a_password_is_set(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'frank.audit@gmail.com', 'password' => '123456', 'userType' => 'student',
            'username' => 'Frank', 'contact' => '09171112225',
        ])->assertStatus(422);

        $customer = $this->user('customer');
        $this->as($this->token($customer))->postJson('/api/auth/change-password', ['currentPassword' => 'secret123', 'password' => 'abcdefgh'])->assertStatus(422);

        $this->as($this->token($this->user('admin')))->postJson('/api/admin/accounts/driver', [
            'UserName' => 'R3', 'Email' => 'rider.three@gmail.com', 'Contact' => '09170000003', 'password' => '123456',
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------- S8

    public function test_S8_temporary_password_must_be_changed_before_using_the_api(): void
    {
        $rider = $this->user('driver', null, ['MustChangePassword' => true]);
        $token = $this->token($rider);

        $this->as($token)->getJson('/api/orders')->assertForbidden();
        $this->as($token)->getJson('/api/auth/me')->assertOk();

        // The temporary-password flow itself keeps working.
        $this->as($token)->postJson('/api/auth/change-password', ['currentPassword' => 'secret123', 'password' => 'Rider12345'])->assertOk();
        $this->assertFalse($rider->fresh()->MustChangePassword);
        $this->as($token)->getJson('/api/orders')->assertOk();
    }

    // -------------------------------------------------------------- S6 / R1

    public function test_S6_login_is_throttled_per_account_even_when_the_ip_changes(): void
    {
        $this->user('customer', 'target.audit@example.com');

        foreach (range(1, 5) as $attempt) {
            $this->withHeader('X-Forwarded-For', "10.9.{$attempt}.7")
                ->postJson('/api/auth/login', ['email' => 'target.audit@example.com', 'password' => "wrong{$attempt}"])
                ->assertUnauthorized();
        }

        $this->withHeader('X-Forwarded-For', '10.99.0.1')
            ->postJson('/api/auth/login', ['email' => 'target.audit@example.com', 'password' => 'wrong6'])
            ->assertStatus(429);
    }

    public function test_R1_chat_traffic_does_not_use_up_the_order_rate_limit(): void
    {
        $customer = $this->user('customer');
        $token = $this->token($customer);
        $order = $this->order($customer, 'confirmed', $this->user('driver'));

        foreach (range(1, 20) as $n) {
            $this->as($token)->postJson("/api/orders/{$order->OrderID}/messages", ['body' => "msg {$n}"])->assertCreated();
        }

        $this->as($token)->postJson('/api/orders', [
            'items' => [['ProductID' => $this->product()->ProductID, 'quantity' => 1]], 'deliveryAddress' => 'PNR Main',
        ])->assertCreated();
    }

    // ---------------------------------------------------------- R2 / R8 / R9

    public function test_R2_custom_item_count_and_order_total_are_capped(): void
    {
        $token = $this->token($this->user('customer'));
        $many = array_fill(0, 51, ['name' => 'Thing', 'quantity' => 1, 'price' => 1]);
        $this->as($token)->postJson('/api/orders', ['customItems' => $many, 'deliveryAddress' => 'PNR Main'])->assertStatus(422);

        $huge = array_fill(0, 2, ['name' => 'Gold', 'quantity' => 99, 'price' => 999999.99]);
        $this->as($token)->postJson('/api/orders', ['customItems' => $huge, 'deliveryAddress' => 'PNR Main'])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_R8_delivery_address_must_start_with_a_supported_zone(): void
    {
        $token = $this->token($this->user('customer'));
        $this->as($token)->postJson('/api/orders', [
            'items' => [['ProductID' => $this->product()->ProductID, 'quantity' => 1]],
            'deliveryAddress' => '<img src=x onerror=alert(1)> Manila, not PNR Main at all',
        ])->assertStatus(422);
    }

    public function test_R9_reads_are_rate_limited(): void
    {
        $token = $this->token($this->user('customer'));
        $codes = collect(range(1, 601))->map(fn () => $this->as($token)->getJson('/api/notifications')->status());
        // 600 a minute leaves room for several open tabs (~120/min each).
        $this->assertFalse($codes->take(600)->contains(429), 'A user with several tabs open was throttled.');
        $this->assertSame(429, $codes->last(), 'Expected a 429 after 600 back-to-back reads.');
    }

    public function test_R9_public_routes_allow_a_shared_campus_ip(): void
    {
        foreach (['api/catalog', 'api/catalog/best-sellers', 'api/catalog/brands/{brand}/products', 'api/health/db', 'api/uploads/bill-documents/{filename}'] as $uri) {
            $route = collect(app('router')->getRoutes())->first(fn ($route) => $route->uri() === $uri);
            $this->assertContains('throttle:1200,1,public', $route->gatherMiddleware(), $uri);
        }
    }

    public function test_R9_malformed_admin_date_filter_is_a_validation_error_not_a_crash(): void
    {
        $this->as($this->token($this->user('admin')))->getJson('/api/admin/orders?date=notadate')->assertStatus(422);
    }

    // -------------------------------------------------------------------- S4

    public function test_S4_rider_cannot_skip_acceptance_or_steps(): void
    {
        $customer = $this->user('customer');
        $rider = $this->user('driver');
        $riderToken = $this->token($rider);

        $this->setStatus($riderToken, $this->order($customer, 'pending_rider', $rider), 'delivered')->assertStatus(422);
        $this->setStatus($riderToken, $this->order($customer, 'confirmed', $rider), 'out_for_delivery')->assertStatus(422);
        $this->setStatus($riderToken, $this->order($customer, 'out_for_delivery', $rider), 'confirmed')->assertStatus(422);
        $this->setStatus($riderToken, $this->order($customer, 'preparing', $rider), 'cancelled')->assertStatus(422);
    }

    public function test_S4_admin_cannot_jump_an_order_to_delivered_or_accept_for_the_rider(): void
    {
        $customer = $this->user('customer');
        $admin = $this->token($this->user('admin'));

        $this->setStatus($admin, $this->order($customer), 'delivered')->assertStatus(422);
        $this->setStatus($admin, $this->order($customer, 'pending_rider', $this->user('driver')), 'confirmed')->assertStatus(422);
        $this->setStatus($admin, $this->order($customer, 'confirmed', $this->user('driver')), 'delivered')->assertStatus(422);
    }

    public function test_S4_admin_can_only_assign_or_unassign_while_pending(): void
    {
        $customer = $this->user('customer');
        $rider = $this->user('driver');
        $admin = $this->token($this->user('admin'));

        foreach (['confirmed', 'out_for_delivery', 'delivered', 'cancelled'] as $status) {
            $order = $this->order($customer, $status, $status === 'confirmed' || $status === 'out_for_delivery' ? $rider : null);
            $this->as($admin)->patchJson("/api/orders/{$order->OrderID}/assign", ['riderId' => $rider->UserID])->assertStatus(422);
            $this->as($admin)->patchJson("/api/orders/{$order->OrderID}/assign", ['riderId' => null])->assertStatus(422);
        }
    }

    public function test_S4_payment_on_a_cancelled_order_cannot_be_verified(): void
    {
        $order = $this->order($this->user('customer'), 'cancelled');
        $payment = Payment::create(['OrderID' => $order->OrderID, 'PaymentName' => 'Meralco', 'PaymentAmount' => 10, 'PaymentStatus' => 'pending']);

        $this->as($this->token($this->user('admin')))->patchJson("/api/payments/{$payment->PaymentID}/status", ['status' => 'verified'])->assertStatus(422);
        $this->assertSame('pending', $payment->fresh()->PaymentStatus);
    }

    // -------------------------------------------------------------------- S3

    public function test_S3_server_charges_the_flat_zone_fee_in_every_zone_and_ignores_the_client_fee(): void
    {
        $this->atManila('10:00');
        $customer = $this->user('customer');
        $token = $this->token($customer);
        $product = $this->product(100);

        foreach (self::ZONES as $zone) {
            $response = $this->as($token)->postJson('/api/orders', [
                'items' => [['ProductID' => $product->ProductID, 'quantity' => 1]],
                'deliveryAddress' => $zone, 'serviceFee' => 0,
            ])->assertCreated();
            $this->assertEquals(75, (float) $response->json('order.ServiceFee'), $zone);
            $this->assertEquals(175, (float) $response->json('order.TotalPrice'), $zone);
        }
    }

    public function test_S3_custom_item_orders_get_the_server_fee_too(): void
    {
        $this->atManila('10:00');
        $response = $this->as($this->token($this->user('customer')))->postJson('/api/orders', [
            'customItems' => [['name' => 'Siomai', 'quantity' => 2, 'price' => 20]],
            'deliveryAddress' => 'Villa Isidra', 'serviceFee' => 0,
        ])->assertCreated();

        $this->assertEquals(75, (float) $response->json('order.ServiceFee'));
        $this->assertEquals(115, (float) $response->json('order.TotalPrice'));
    }

    #[DataProvider('surchargeCases')]
    public function test_S3_night_surcharge_uses_the_stored_customer_type_and_manila_time(string $userType, string $time, float $expectedFee): void
    {
        $this->atManila($time);
        $customer = $this->user('customer', null, ['UserType' => $userType]);

        $response = $this->as($this->token($customer))->postJson('/api/orders', [
            'items' => [['ProductID' => $this->product()->ProductID, 'quantity' => 1]],
            'deliveryAddress' => 'Bagong Sikat', 'serviceFee' => 75,
        ])->assertCreated();

        $this->assertEquals($expectedFee, (float) $response->json('order.ServiceFee'));
    }

    public static function surchargeCases(): array
    {
        return [
            'student at 20:00 (cutoff, no surcharge)' => ['student', '20:00', 75],
            'student at 20:01 (after cutoff)' => ['student', '20:01', 112.5],
            'student at 19:00 (non-student would pay more)' => ['student', '19:00', 75],
            'non-student at 18:00 (cutoff, no surcharge)' => ['non_student', '18:00', 75],
            'non-student at 18:01 (after cutoff)' => ['non_student', '18:01', 112.5],
            'non-student at 08:00' => ['non_student', '08:00', 75],
        ];
    }

    public function test_S3_pay_bills_fee_is_computed_by_the_server(): void
    {
        $this->atManila('21:00');
        $customer = $this->user('customer', null, ['UserType' => 'student']);

        $response = $this->as($this->token($customer))->postJson('/api/payments', [
            'establishment' => 'Meralco', 'deliveryAddress' => 'Tobias Ville', 'serviceFee' => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('Orders', ['OrderID' => $response->json('orderId'), 'ServiceFee' => 112.5]);
    }

    // -------------------------------------------------------------------- R6

    public function test_R6_pay_bills_requires_a_supported_delivery_zone(): void
    {
        $token = $this->token($this->user('customer'));
        $this->as($token)->postJson('/api/payments', ['establishment' => 'Meralco'])->assertStatus(422);
        $this->as($token)->postJson('/api/payments', ['establishment' => 'Meralco', 'deliveryAddress' => 'Quezon City'])->assertStatus(422);
    }

    public function test_R6_duplicate_bill_submission_returns_the_first_bill(): void
    {
        $token = $this->token($this->user('customer'));
        $bill = ['establishment' => 'Meralco', 'deliveryAddress' => 'PNR Main', 'clientOrderId' => 'bill-dup-1'];

        $first = $this->as($token)->postJson('/api/payments', $bill)->assertCreated();
        $second = $this->as($token)->postJson('/api/payments', $bill)->assertOk();

        $this->assertSame($first->json('orderId'), $second->json('orderId'));
        $this->assertSame(1, Payment::count());
    }

    // -------------------------------------------------------------------- S5

    public function test_S5_customer_only_sees_the_riders_id_and_name(): void
    {
        $customer = $this->user('customer');
        $this->order($customer, 'confirmed', $this->user('driver', null, ['MustChangePassword' => true]));

        $rider = $this->as($this->token($customer))->getJson('/api/orders')->assertOk()->json('data.0.rider');

        $this->assertEqualsCanonicalizing(['UserID', 'UserName'], array_keys($rider));
    }

    public function test_S5_rider_does_not_see_the_customers_email_type_or_account_flags(): void
    {
        $rider = $this->user('driver');
        $this->order($this->user('customer'), 'confirmed', $rider);

        $customer = $this->as($this->token($rider))->getJson('/api/orders')->assertOk()->json('data.0.user');

        foreach (['Email', 'UserType', 'MustChangePassword', 'Role', 'CreatedAt'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $customer);
        }
        // The rider card still shows who and where to deliver to.
        foreach (['UserName', 'Contact', 'Address'] as $shown) {
            $this->assertArrayHasKey($shown, $customer);
        }
    }

    // ------------------------------------------------------------- S9 / S10

    public function test_S9_receipt_urls_must_point_at_an_uploaded_bill_document(): void
    {
        $token = $this->token($this->user('customer'));
        foreach (['javascript:alert(document.cookie)', 'https://evil.example/phish.pdf', '/uploads/bill-documents/../../.env'] as $url) {
            $this->as($token)->postJson('/api/payments', [
                'establishment' => 'Meralco', 'deliveryAddress' => 'PNR Main', 'transferProofUrl' => $url,
            ])->assertStatus(422);
        }
    }

    public function test_S10_bill_documents_are_stored_privately_and_served_only_through_a_signed_link(): void
    {
        Storage::fake('local');
        $customer = $this->user('customer');
        $token = $this->token($customer);

        $url = $this->as($token)->post('/api/uploads/bill-documents', [
            'document' => UploadedFile::fake()->image('receipt.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('url');
        $filename = basename($url);

        $this->assertFileDoesNotExist(public_path('uploads/bill-documents/'.$filename));
        Storage::disk('local')->assertExists('bill-documents/'.$filename);

        // Without a signature the document is not served at all.
        $this->get('/api/uploads/bill-documents/'.$filename)->assertForbidden();

        // The owner's order list hands out a working, signed link.
        $this->as($token)->postJson('/api/payments', [
            'establishment' => 'Meralco', 'deliveryAddress' => 'PNR Main', 'billReceiptUrl' => $url,
        ])->assertCreated();
        $note = json_decode($this->as($token)->getJson('/api/orders')->json('data.0.payments.0.PaymentNote'), true);
        $this->assertStringContainsString('signature=', $note['billReceiptUrl']);
        $this->app['auth']->forgetGuards();
        $this->get($note['billReceiptUrl'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ------------------------------------------------------------ still works

    public function test_still_works_cross_user_access_is_blocked(): void
    {
        $alice = $this->user('customer');
        $bob = $this->token($this->user('customer'));
        $otherRider = $this->token($this->user('driver'));
        $order = $this->order($alice, 'confirmed', $this->user('driver'));

        $this->setStatus($bob, $order, 'cancelled')->assertForbidden();
        $this->as($bob)->getJson("/api/orders/{$order->OrderID}/messages")->assertForbidden();
        $this->as($bob)->postJson("/api/orders/{$order->OrderID}/report", ['reason' => 'x'])->assertForbidden();
        $this->setStatus($otherRider, $order, 'preparing')->assertForbidden();
        $this->as($bob)->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->as($bob)->getJson('/api/admin/orders')->assertForbidden();
        $this->as($otherRider)->getJson('/api/admin/revenue')->assertForbidden();
    }

    public function test_still_works_role_escalation_through_register_and_profile_is_ignored(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'email' => 'carol.escalate@gmail.com', 'password' => 'Secret123', 'userType' => 'student',
            'username' => 'Carol', 'contact' => '09171112222', 'Role' => 'admin', 'role' => 'admin', 'UserID' => 1,
        ])->assertCreated()->assertJsonPath('user.role', 'customer')->json('token');

        $this->as($token)->patchJson('/api/auth/me', [
            'username' => 'Carol', 'email' => 'carol.escalate@gmail.com', 'address' => 'PNR Main', 'Role' => 'admin', 'role' => 'admin',
        ])->assertOk()->assertJsonPath('user.role', 'customer');
    }

    public function test_still_works_order_input_limits(): void
    {
        $token = $this->token($this->user('customer'));
        $product = $this->product();
        foreach ([0, -5, 100, 1.5] as $quantity) {
            $this->as($token)->postJson('/api/orders', [
                'items' => [['ProductID' => $product->ProductID, 'quantity' => $quantity]], 'deliveryAddress' => 'PNR Main',
            ])->assertStatus(422);
        }
        $this->as($token)->postJson('/api/orders', [
            'items' => [['ProductID' => 999999, 'quantity' => 1]], 'deliveryAddress' => 'PNR Main',
        ])->assertStatus(422);
        $this->as($token)->postJson('/api/orders', [
            'items' => [['ProductID' => $product->ProductID, 'quantity' => 1]], 'deliveryAddress' => 'Manila City Hall',
        ])->assertStatus(422);
    }

    public function test_still_works_duplicate_order_guard(): void
    {
        $token = $this->token($this->user('customer'));
        $body = ['items' => [['ProductID' => $this->product()->ProductID, 'quantity' => 1]], 'deliveryAddress' => 'PNR Main', 'clientOrderId' => 'dup-1'];

        $first = $this->as($token)->postJson('/api/orders', $body)->assertCreated()->json('order.OrderID');
        $this->as($token)->postJson('/api/orders', $body)->assertOk()->assertJsonPath('order.OrderID', $first);
        $this->assertSame(1, Order::count());
    }

    public function test_still_works_rider_decline_returns_the_order_to_the_admin(): void
    {
        $rider = $this->user('driver');
        $order = $this->order($this->user('customer'), 'pending_rider', $rider);

        $this->setStatus($this->token($rider), $order, 'cancelled')
            ->assertOk()->assertJsonPath('order.DeliveryStatus', 'pending_rider')->assertJsonPath('order.AssignedRiderID', null);
    }

    public function test_still_works_customer_cancels_while_pending_and_unassigned(): void
    {
        $customer = $this->user('customer');
        $this->setStatus($this->token($customer), $this->order($customer), 'cancelled')
            ->assertOk()->assertJsonPath('order.DeliveryStatus', 'cancelled');
    }

    public function test_still_works_rider_moves_an_order_forward_one_step_at_a_time(): void
    {
        $rider = $this->user('driver');
        $token = $this->token($rider);
        $order = $this->order($this->user('customer'), 'pending_rider', $rider);

        foreach (['confirmed', 'preparing', 'out_for_delivery', 'delivered'] as $status) {
            $this->setStatus($token, $order, $status)->assertOk()->assertJsonPath('order.DeliveryStatus', $status);
        }
    }

    public function test_still_works_admin_cancels_and_steps_bill_orders_forward(): void
    {
        $customer = $this->user('customer');
        $admin = $this->token($this->user('admin'));

        $this->setStatus($admin, $this->order($customer, 'confirmed', $this->user('driver')), 'cancelled')->assertOk();
        $this->setStatus($admin, $this->order($customer, 'confirmed', $this->user('driver')), 'preparing')->assertOk();
    }
}
