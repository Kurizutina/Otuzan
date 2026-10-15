<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// An admin can block a prank/abusive customer: the account can't log in and its
// sessions end at once. Unblocking lets it log in again.
class BlockAccountTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email): User
    {
        return User::create([
            'UserName' => ucfirst($role), 'Email' => $email, 'Role' => $role, 'Contact' => '09171234567',
            'PasswordHash' => Hash::make('Zebra2026x'), 'Address' => 'PNR Main',
        ]);
    }

    private function as(User $user, ?string $token = null): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($token ?? $user->createToken('t')->plainTextToken);
    }

    private function login(string $email, string $password = 'Zebra2026x')
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => ''])->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_a_blocked_customer_is_signed_out_and_cannot_log_in_until_unblocked(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $customer = $this->user('customer', 'prank@example.com');
        $session = $customer->createToken('phone')->plainTextToken;
        $this->as($customer, $session)->getJson('/api/auth/me')->assertOk();

        $this->as($admin)->patchJson("/api/admin/accounts/customer/{$customer->UserID}/block", ['blocked' => true])
            ->assertOk()->assertJsonPath('UserID', $customer->UserID);
        $this->assertNotNull($customer->fresh()->BlockedAt);

        $this->as($customer, $session)->getJson('/api/auth/me')->assertUnauthorized();
        $this->login('prank@example.com')->assertForbidden()
            ->assertJsonPath('error', 'This account has been blocked. Please contact the shop.');
        // A wrong password still says only "invalid credentials" - blocked status is not revealed.
        $this->login('prank@example.com', 'WrongPass1')->assertUnauthorized();

        $this->as($admin)->patchJson("/api/admin/accounts/customer/{$customer->UserID}/block", ['blocked' => false])->assertOk();
        $this->assertNull($customer->fresh()->BlockedAt);
        $this->login('prank@example.com')->assertOk();
    }

    public function test_the_customer_list_shows_who_is_blocked(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $customer = $this->user('customer', 'prank@example.com');
        $this->as($admin)->patchJson("/api/admin/accounts/customer/{$customer->UserID}/block", ['blocked' => true])->assertOk();

        $row = collect($this->as($admin)->getJson('/api/admin/accounts/customer')->assertOk()->json('data'))
            ->firstWhere('UserID', $customer->UserID);
        $this->assertNotNull($row['BlockedAt']);
    }

    public function test_only_admins_can_block_and_only_customers_can_be_blocked(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $customer = $this->user('customer', 'prank@example.com');
        $other = $this->user('customer', 'other@example.com');
        $rider = $this->user('driver', 'rider@example.com');

        $this->as($other)->patchJson("/api/admin/accounts/customer/{$customer->UserID}/block", ['blocked' => true])->assertForbidden();
        $this->as($rider)->patchJson("/api/admin/accounts/customer/{$customer->UserID}/block", ['blocked' => true])->assertForbidden();
        $this->as($admin)->patchJson("/api/admin/accounts/driver/{$rider->UserID}/block", ['blocked' => true])->assertNotFound();
        $this->as($admin)->patchJson("/api/admin/accounts/customer/{$rider->UserID}/block", ['blocked' => true])->assertNotFound();
        $this->as($admin)->patchJson("/api/admin/accounts/customer/{$customer->UserID}/block", ['blocked' => 'maybe'])->assertUnprocessable();

        $this->assertNull($customer->fresh()->BlockedAt);
        $this->assertNull($rider->fresh()->BlockedAt);
    }
}
