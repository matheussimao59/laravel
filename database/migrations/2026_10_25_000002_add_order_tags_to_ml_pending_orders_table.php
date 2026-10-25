<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_pending_orders', function (Blueprint $table) {
            $table->json('order_tags')->nullable()->after('order_status');
        });
    }

    public function down(): void
    {
        Schema::table('ml_pending_orders', function (Blueprint $table) {
            if (Schema::hasColumn('ml_pending_orders', 'order_tags')) {
                $table->dropColumn('order_tags');
            }
        });
    }
};
