<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('modelos') || Schema::hasColumn('modelos', 'verso_path')) {
            return;
        }

        Schema::table('modelos', function (Blueprint $table) {
            $table->string('verso_name')->nullable()->after('pdf_path');
            $table->string('verso_path')->nullable()->after('verso_name');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('modelos')) {
            return;
        }

        Schema::table('modelos', function (Blueprint $table) {
            if (Schema::hasColumn('modelos', 'verso_path')) {
                $table->dropColumn(['verso_name', 'verso_path']);
            }
        });
    }
};
