<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('modelos') || Schema::hasColumn('modelos', 'rule_theme')) {
            return;
        }

        Schema::table('modelos', function (Blueprint $table) {
            $table->string('rule_theme')->nullable()->after('rule_name');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('modelos')) {
            return;
        }

        Schema::table('modelos', function (Blueprint $table) {
            if (Schema::hasColumn('modelos', 'rule_theme')) {
                $table->dropColumn('rule_theme');
            }
        });
    }
};
