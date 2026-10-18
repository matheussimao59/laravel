<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_arte_plotters', function (Blueprint $table) {
            if (!Schema::hasColumn('gp_arte_plotters', 'marks')) {
                $table->json('marks')->nullable()->after('items');
            }
            if (!Schema::hasColumn('gp_arte_plotters', 'hide_default_marks')) {
                $table->boolean('hide_default_marks')->default(false)->after('marks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gp_arte_plotters', function (Blueprint $table) {
            if (Schema::hasColumn('gp_arte_plotters', 'marks')) {
                $table->dropColumn('marks');
            }
            if (Schema::hasColumn('gp_arte_plotters', 'hide_default_marks')) {
                $table->dropColumn('hide_default_marks');
            }
        });
    }
};
