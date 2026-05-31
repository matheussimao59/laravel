<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('local_print_jobs') || Schema::hasColumn('local_print_jobs', 'print_profile')) {
            return;
        }

        Schema::table('local_print_jobs', function (Blueprint $table) {
            $table->longText('print_profile')->nullable()->after('page_order');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('local_print_jobs') || !Schema::hasColumn('local_print_jobs', 'print_profile')) {
            return;
        }

        Schema::table('local_print_jobs', function (Blueprint $table) {
            $table->dropColumn('print_profile');
        });
    }
};
