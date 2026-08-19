<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('category')->constrained('gp_categories')->nullOnDelete();
            $table->string('pricing_type')->nullable()->default('fixed')->after('sell_price');
        });
    }

    public function down(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'pricing_type']);
        });
    }
};
