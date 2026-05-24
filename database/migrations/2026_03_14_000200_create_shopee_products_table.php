<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopee_products')) {
            return;
        }

        Schema::create('shopee_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('product_name', 190);
            $table->decimal('original_price', 14, 2)->default(0);
            $table->decimal('production_cost', 14, 2)->default(0);
            $table->json('materials_json')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_name']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_products');
    }
};
