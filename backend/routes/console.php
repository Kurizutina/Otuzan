<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('otuzan:db-check', function () {
    \Illuminate\Support\Facades\DB::select('SELECT 1');
    $this->info('Laravel connected to '.config('database.connections.mysql.database').' on port '.config('database.connections.mysql.port'));
})->purpose('Check the Otu-Zan database connection');

Artisan::command('otuzan:make-admin {email : Login email} {name : Display name} {--password= : Password; prompted when omitted} {--contact= : Contact number} {--user-type=non_student : student or non_student}', function () {
    $email = strtolower(trim($this->argument('email')));
    $name = trim($this->argument('name'));
    $userType = $this->option('user-type');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email address.');
        return 1;
    }
    if ($name === '') {
        $this->error('Display name cannot be empty.');
        return 1;
    }
    if (!in_array($userType, ['student', 'non_student'], true)) {
        $this->error('--user-type must be "student" or "non_student".');
        return 1;
    }
    if (User::where('Email', $email)->exists()) {
        $this->error("An account with email {$email} already exists.");
        return 1;
    }

    $password = $this->option('password') ?? $this->secret('Password');
    if (strlen($password) < 6 || strlen($password) > 72) {
        $this->error('Password must be between 6 and 72 characters.');
        return 1;
    }

    // Same field shape AccountManagementController::store builds for staff,
    // minus Role-agnostic validation: Role is fixed to admin here and
    // MustChangePassword stays false because this command sets the initial
    // password interactively (the forced-change flag exists for driver
    // onboarding, where an admin hands out a temporary password).
    $admin = User::create([
        'UserName' => $name,
        'Email' => $email,
        'Contact' => trim((string) $this->option('contact')) ?: null,
        'Role' => 'admin',
        'UserType' => $userType,
        'PasswordHash' => Hash::make($password),
        'MustChangePassword' => false,
    ]);

    $this->info("Admin account created: UserID {$admin->UserID} ({$email}).");
})->purpose('Create the first admin account on a fresh deployment');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
