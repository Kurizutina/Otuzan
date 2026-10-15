<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Users', function (Blueprint $table) {
            // Set by an admin to stop a prank/abusive customer: a blocked account
            // can't log in, and its sessions are ended when it is blocked.
            $table->dateTime('BlockedAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Users', function (Blueprint $table) {
            $table->dropColumn('BlockedAt');
        });
    }
};
