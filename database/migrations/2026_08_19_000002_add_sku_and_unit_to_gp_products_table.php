<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('name');
            $table->string('unit')->nullable()->default('un')->after('stock_qty');
        });
    }

    public function down(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->dropColumn(['sku', 'unit']);
        });
    }
};