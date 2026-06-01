<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('local_print_jobs') || Schema::hasColumn('local_print_jobs', 'print_side')) {
            return;
        }

        Schema::table('local_print_jobs', function (Blueprint $table) {
            $table->string('print_side', 16)->default('front')->after('print_profile');
            $table->index(['user_id', 'manual_print_order_id', 'print_side', 'status'], 'local_print_jobs_order_side_status_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('local_print_jobs') || !Schema::hasColumn('local_print_jobs', 'print_side')) {
            return;
        }

        Schema::table('local_print_jobs', function (Blueprint $table) {
            $table->dropIndex('local_print_jobs_order_side_status_idx');
            $table->dropColumn('print_side');
        });
    }
};
