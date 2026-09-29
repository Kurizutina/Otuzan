<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_first_admin_account_with_hashed_password(): void
    {
        $this->artisan('otuzan:make-admin', [
            'email' => 'FirstAdmin@Example.com ',
            'name' => '  First Admin',
            '--password' => 'secret123',
            '--contact' => '09171234567',
        ])->assertExitCode(0);

        $admin = User::where('Email', 'firstadmin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame('First Admin', $admin->UserName);
        $this->assertSame('admin', $admin->Role);
        $this->assertSame('non_student', $admin->UserType);
        $this->assertSame('09171234567', $admin->Contact);
        $this->assertFalse($admin->MustChangePassword);
        $this->assertTrue(Hash::check('secret123', $admin->PasswordHash));
        $this->assertNotSame('secret123', $admin->PasswordHash);
    }

    public function test_student_user_type_flag_is_honored(): void
    {
        $this->artisan('otuzan:make-admin', [
            'email' => 'studentadmin@example.com',
            'name' => 'Student Admin',
            '--password' => 'secret123',
            '--user-type' => 'student',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('Users', [
            'Email' => 'studentadmin@example.com', 'Role' => 'admin', 'UserType' => 'student',
        ]);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::create([
            'UserName' => 'Existing Admin', 'Email' => 'taken@example.com', 'Role' => 'admin',
            'Contact' => '09123456789', 'PasswordHash' => Hash::make('secret123'),
        ]);

        $this->artisan('otuzan:make-admin', [
            'email' => 'taken@example.com',
            'name' => 'Second Admin',
            '--password' => 'secret123',
        ])->assertExitCode(1);

        $this->assertSame(1, User::where('Email', 'taken@example.com')->count());
    }

    public function test_rejects_invalid_email_user_type_and_short_password(): void
    {
        $this->artisan('otuzan:make-admin', [
            'email' => 'not-an-email', 'name' => 'Admin', '--password' => 'secret123',
        ])->assertExitCode(1);

        $this->artisan('otuzan:make-admin', [
            'email' => 'admin@example.com', 'name' => 'Admin', '--password' => 'secret123',
            '--user-type' => 'guest',
        ])->assertExitCode(1);

        $this->artisan('otuzan:make-admin', [
            'email' => 'admin@example.com', 'name' => 'Admin', '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('Users', ['Email' => 'admin@example.com']);
    }
}
