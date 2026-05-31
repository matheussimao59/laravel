<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('manual_print_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('manual_print_orders', 'platform_order_id')) {
                $table->string('platform_order_id', 120)->nullable()->after('modelo_id');
                $table->index(['user_id', 'platform_order_id'], 'manual_print_orders_user_platform_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manual_print_orders', function (Blueprint $table) {
            if (Schema::hasColumn('manual_print_orders', 'platform_order_id')) {
                $table->dropIndex('manual_print_orders_user_platform_idx');
                $table->dropColumn('platform_order_id');
            }
        });
    }
};
