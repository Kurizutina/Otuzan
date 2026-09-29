<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Orders', function (Blueprint $table) {
            // Post-delivery report window: a customer has 24h after delivery
            // to flag a problem, which reopens the order chat for both sides
            // and marks the order on the admin History tab until dismissed.
            // ReportedAt doubles as the flag (null = no open report); the
            // window itself is enforced server-side in OrderController::
            // report against StatusUpdatedAt (delivery time), not stored.
            $table->dateTime('ReportedAt')->nullable()->after('StatusUpdatedAt');
            $table->string('ReportReason', 2000)->nullable()->after('ReportedAt');
        });
    }

    public function down(): void
    {
        Schema::table('Orders', function (Blueprint $table) {
            $table->dropColumn(['ReportedAt', 'ReportReason']);
        });
    }
};
