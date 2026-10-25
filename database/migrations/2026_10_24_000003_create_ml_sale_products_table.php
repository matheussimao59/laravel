<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->string('item_id', 50);
            $table->string('variation_id', 60)->default('');
            $table->foreignId('gp_product_id')->nullable()->constrained('gp_products')->nullOnDelete();
            $table->string('title');
            $table->string('sku', 120)->nullable();
            $table->text('thumbnail')->nullable();
            $table->json('pictures')->nullable();
            $table->longText('description')->nullable();
            $table->text('permalink')->nullable();
            $table->decimal('listing_price', 12, 2)->nullable();
            $table->decimal('last_sale_price', 12, 2)->nullable();
            $table->unsignedBigInteger('last_order_id')->nullable();
            $table->timestamp('last_sale_at')->nullable();
            $table->timestamp('details_synced_at')->nullable();
            $table->timestamp('costs_configured_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'seller_id', 'item_id', 'variation_id'], 'ml_sale_products_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_sale_products');
    }
};
