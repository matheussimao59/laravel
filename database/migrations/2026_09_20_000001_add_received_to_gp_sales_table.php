<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_sales', function (Blueprint $table) {
            $table->decimal('received', 12, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('gp_sales', function (Blueprint $table) {
            $table->dropColumn('received');
        });
    }
};