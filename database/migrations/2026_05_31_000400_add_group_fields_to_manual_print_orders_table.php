<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('manual_print_orders', function (Blueprint $table) {
            if (Schema::hasColumn('manual_print_orders', 'platform_order_id')) {
                try {
                    $table->dropUnique('manual_print_orders_user_platform_unique');
                } catch (\Throwable) {
                    // O indice pode nao existir em bancos que ainda nao rodaram a migration anterior.
                }
            }

            if (!Schema::hasColumn('manual_print_orders', 'is_group_order')) {
                $table->boolean('is_group_order')->default(false)->after('platform_order_id');
            }

            if (!Schema::hasColumn('manual_print_orders', 'group_size')) {
                $table->unsignedInteger('group_size')->nullable()->after('is_group_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manual_print_orders', function (Blueprint $table) {
            if (Schema::hasColumn('manual_print_orders', 'group_size')) {
                $table->dropColumn('group_size');
            }

            if (Schema::hasColumn('manual_print_orders', 'is_group_order')) {
                $table->dropColumn('is_group_order');
            }
        });
    }
};
