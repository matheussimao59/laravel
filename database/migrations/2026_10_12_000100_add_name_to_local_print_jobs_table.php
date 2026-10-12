<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('local_print_jobs') || Schema::hasColumn('local_print_jobs', 'name')) {
            return;
        }

        Schema::table('local_print_jobs', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->after('manual_print_order_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('local_print_jobs') || !Schema::hasColumn('local_print_jobs', 'name')) {
            return;
        }

        Schema::table('local_print_jobs', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
