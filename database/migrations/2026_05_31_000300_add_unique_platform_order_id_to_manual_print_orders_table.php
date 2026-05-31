<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('manual_print_orders', 'platform_order_id')) {
            return;
        }

        DB::statement("
            DELETE mpo1 FROM manual_print_orders mpo1
            INNER JOIN manual_print_orders mpo2
                ON mpo1.user_id = mpo2.user_id
                AND mpo1.platform_order_id = mpo2.platform_order_id
                AND mpo1.id > mpo2.id
            WHERE mpo1.platform_order_id IS NOT NULL
        ");

        Schema::table('manual_print_orders', function (Blueprint $table) {
            $table->unique(['user_id', 'platform_order_id'], 'manual_print_orders_user_platform_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('manual_print_orders', 'platform_order_id')) {
            return;
        }

        Schema::table('manual_print_orders', function (Blueprint $table) {
            $table->dropUnique('manual_print_orders_user_platform_unique');
        });
    }
};
