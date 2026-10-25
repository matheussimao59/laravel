<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasColumn('manual_print_orders', 'platform_order_id')
            && $this->indexExists('manual_print_orders', 'manual_print_orders_user_platform_unique')
        ) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                DB::statement('drop index manual_print_orders_user_platform_unique');
            } else {
                DB::statement('alter table manual_print_orders drop index manual_print_orders_user_platform_unique');
            }
        }

        Schema::table('manual_print_orders', function (Blueprint $table) {
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

    private function indexExists(string $table, string $index): bool
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'])) {
            return count(DB::select("show index from {$table} where Key_name = ?", [$index])) > 0;
        }

        foreach (Schema::getIndexes($table) as $existing) {
            if (strtolower((string) ($existing['name'] ?? '')) === strtolower($index)) {
                return true;
            }
        }

        return false;
    }
};
