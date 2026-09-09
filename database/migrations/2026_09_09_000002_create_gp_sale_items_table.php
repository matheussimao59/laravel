<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('gp_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('gp_products')->cascadeOnDelete();
            $table->string('product_name')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_sale_items');
    }
};