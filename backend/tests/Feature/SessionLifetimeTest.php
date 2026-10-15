<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// How long a login lasts depends on the role (AuthController::SESSION_DAYS):
// the browser keeps it across restarts, so riders aren't logged out mid-shift.
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return ['customer' => ['customer', 30], 'driver' => ['driver', 14], 'admin' => ['admin', 1]];
    }

    /** @dataProvider roles */
    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_login_lasts_as_long_as_the_role_allows(string $role, int $days): void
    {
        $user = User::create([
            'UserName' => ucfirst($role), 'Email' => "{$role}@example.com", 'Role' => $role,
            'Contact' => '09123456789', 'PasswordHash' => Hash::make('Zebra2026x'), 'Address' => 'PNR Main',
        ]);

        $token = $this->postJson('/api/auth/login', ['email' => $user->Email, 'password' => 'Zebra2026x'])
            ->assertOk()->json('token');

        $expiresAt = PersonalAccessToken::findToken($token)->expires_at;
        $this->assertEqualsWithDelta(now()->addDays($days)->timestamp, $expiresAt->timestamp, 60);
    }

    public function test_a_new_customer_account_is_signed_in_for_30_days(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'email' => 'new.customer@example.com', 'password' => 'Zebra2026x', 'userType' => 'student',
            'username' => 'New Customer', 'contact' => '09123456789', 'address' => 'PNR Main',
        ])->assertCreated()->json('token');

        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, PersonalAccessToken::findToken($token)->expires_at->timestamp, 60);
    }
}
