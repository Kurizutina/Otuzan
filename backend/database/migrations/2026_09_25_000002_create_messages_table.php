<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FK columns must match the parent columns' type+signedness exactly on
        // MySQL: Orders.OrderID/Users.UserID are signed int(11) on a database
        // imported from the team's shared SQL dump, but unsigned when created
        // fresh by increments() (see 0001_01_01_000000_create_users_table.php
        // and 2026_09_17_000001_create_ordering_tables.php). Detect what this
        // database actually has instead of assuming one - assuming signed
        // broke a fresh MySQL migrate with errno 150, which the sqlite-based
        // test suite never sees. Same approach as
        // add_assigned_rider_to_orders_table.
        $orderIdUnsigned = true;
        $userIdUnsigned = true;
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $orderId = DB::select("SHOW COLUMNS FROM Orders WHERE Field = 'OrderID'")[0] ?? null;
            $userId = DB::select("SHOW COLUMNS FROM Users WHERE Field = 'UserID'")[0] ?? null;
            $orderIdUnsigned = $orderId ? str_contains(strtolower($orderId->Type), 'unsigned') : true;
            $userIdUnsigned = $userId ? str_contains(strtolower($userId->Type), 'unsigned') : true;
        }

        Schema::create('Message', function (Blueprint $table) use ($orderIdUnsigned, $userIdUnsigned) {
            $table->increments('MessageID');
            $orderIdUnsigned
                ? $table->unsignedInteger('OrderID')
                : $table->integer('OrderID');
            $userIdUnsigned
                ? $table->unsignedInteger('SenderUserID')
                : $table->integer('SenderUserID');
            $table->string('MessageBody', 1000);
            $table->boolean('MessageSeen')->default(false);
            $table->dateTime('MessageDate');
            $table->foreign('OrderID')->references('OrderID')->on('Orders')->cascadeOnDelete();
            $table->foreign('SenderUserID')->references('UserID')->on('Users')->cascadeOnDelete();
            $table->index(['OrderID', 'MessageDate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Message');
    }
};
