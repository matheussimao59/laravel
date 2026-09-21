<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_product_discount_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('gp_products')->cascadeOnDelete();
            $table->integer('min_qty')->default(1);
            $table->string('discount_type')->default('percent');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_product_discount_tiers');
    }
};