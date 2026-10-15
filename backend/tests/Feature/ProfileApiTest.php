<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_only_authenticated_account_and_preserves_blank_password(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $oldHash = $user->PasswordHash;
        $this->withToken($user->createToken('test')->plainTextToken)->patchJson('/api/auth/me', [
            'username' => 'New Full Name', 'address' => 'New Address',
            'password' => '', 'id' => $other->UserID, 'role' => 'admin',
        ])->assertOk()->assertJsonPath('user.id', $user->UserID)->assertJsonPath('user.role', 'customer');
        $this->assertDatabaseHas('Users', ['UserID' => $user->UserID, 'UserName' => 'New Full Name', 'Email' => $user->Email, 'Address' => 'New Address']);
        $this->assertSame($oldHash, $user->fresh()->PasswordHash);
        $this->assertSame($other->UserName, $other->fresh()->UserName);
    }

    public function test_password_change_is_hashed_and_used_on_next_login(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)->patchJson('/api/auth/me', [
            'username' => 'Updated Name', 'address' => 'New Address', 'password' => 'changed123', 'currentPassword' => 'password',
        ])->assertOk()->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.PasswordHash');
        $this->assertTrue(Hash::check('changed123', $user->fresh()->PasswordHash));
        $this->postJson('/api/auth/login', ['email' => $user->Email, 'password' => 'changed123', 'role' => 'customer'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->Email, 'password' => 'password', 'role' => 'customer'])->assertUnauthorized();
    }

    public function test_unauthenticated_and_invalid_updates_do_not_change_profile(): void
    {
        $user = User::factory()->create();
        $data = ['username' => 'New Name', 'address' => 'New Address'];
        $this->patchJson('/api/auth/me', $data)->assertUnauthorized();
        $this->withToken($user->createToken('test')->plainTextToken)
            ->patchJson('/api/auth/me', ['username' => ' ', 'address' => ''])->assertUnprocessable();
        $this->assertSame($user->UserName, $user->fresh()->UserName);
        $this->assertSame($user->Email, $user->fresh()->Email);
    }

    // The email is the login and the reset address; a typo would lock the
    // customer out, so only an admin (Accounts) can change it.
    public function test_a_customer_cannot_change_their_email_from_the_profile(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->withToken($user->createToken('test')->plainTextToken);
        $base = ['username' => 'Name', 'address' => 'Address', 'currentPassword' => 'password'];

        foreach (['real.person@example.com', $other->Email, 'a@ce', 'someone@mailinator.com'] as $email) {
            $token->patchJson('/api/auth/me', [...$base, 'email' => $email])
                ->assertUnprocessable()->assertJsonPath('error', 'Your email address cannot be changed here. Please contact the shop.');
        }
        $token->patchJson('/api/auth/me', [...$base, 'email' => ['array@example.com']])->assertUnprocessable();
        $this->assertSame($user->Email, $user->fresh()->Email);
        $this->assertSame($user->UserName, $user->fresh()->UserName);

        // Sending the account's own email, in any case, is harmless.
        $token->patchJson('/api/auth/me', [...$base, 'email' => strtoupper($user->Email)])->assertOk();
        $this->assertSame($user->Email, $user->fresh()->Email);
    }
}