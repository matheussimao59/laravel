<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_orders', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at'], 'shipping_orders_user_updated_idx');
            $table->index(['user_id', 'platform_order_number'], 'shipping_orders_user_platform_idx');
        });

        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->index(['user_id', 'due_date'], 'financial_transactions_user_due_idx');
        });

        Schema::table('calendar_orders', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'calendar_orders_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_orders', function (Blueprint $table) {
            $table->dropIndex('calendar_orders_user_created_idx');
        });

        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropIndex('financial_transactions_user_due_idx');
        });

        Schema::table('shipping_orders', function (Blueprint $table) {
            $table->dropIndex('shipping_orders_user_platform_idx');
            $table->dropIndex('shipping_orders_user_updated_idx');
        });
    }
};
