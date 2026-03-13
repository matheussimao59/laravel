<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cover_agenda_items')) {
            Schema::table('cover_agenda_items', function (Blueprint $table) {
                $table->index(['user_id', 'order_id'], 'cover_agenda_items_user_order_idx');
            });
        }

        if (Schema::hasTable('calendar_orders')) {
            Schema::table('calendar_orders', function (Blueprint $table) {
                $table->index(['user_id', 'order_id'], 'calendar_orders_user_order_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cover_agenda_items')) {
            Schema::table('cover_agenda_items', function (Blueprint $table) {
                $table->dropIndex('cover_agenda_items_user_order_idx');
            });
        }

        if (Schema::hasTable('calendar_orders')) {
            Schema::table('calendar_orders', function (Blueprint $table) {
                $table->dropIndex('calendar_orders_user_order_idx');
            });
        }
    }
};
