<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Every order list sorts by OrderDate and filters by customer, rider or
    // status; the live screens also look for orders finished in the last day.
    // Without these, each 2-second poll scans the whole Orders table.
    public function up(): void
    {
        Schema::table('Orders', function (Blueprint $table) {
            $table->index('OrderDate', 'orders_orderdate_index');
            $table->index(['DeliveryStatus', 'OrderDate'], 'orders_status_orderdate_index');
            $table->index(['UserID', 'OrderDate'], 'orders_user_orderdate_index');
            $table->index(['AssignedRiderID', 'DeliveryStatus'], 'orders_rider_status_index');
            $table->index('StatusUpdatedAt', 'orders_statusupdatedat_index');
        });
    }

    public function down(): void
    {
        Schema::table('Orders', function (Blueprint $table) {
            $table->dropIndex('orders_orderdate_index');
            $table->dropIndex('orders_status_orderdate_index');
            $table->dropIndex('orders_user_orderdate_index');
            $table->dropIndex('orders_rider_status_index');
            $table->dropIndex('orders_statusupdatedat_index');
        });
    }
};
