<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Orders', function (Blueprint $table) {
            // When the rider accepted. StatusUpdatedAt moves on with every
            // later step, so the formula-based delivery countdown (see
            // OrderController::attachDeliveryEstimates) needs its own start.
            $table->dateTime('ConfirmedAt')->nullable()->after('StatusUpdatedAt');
        });
    }

    public function down(): void
    {
        Schema::table('Orders', function (Blueprint $table) {
            $table->dropColumn('ConfirmedAt');
        });
    }
};
