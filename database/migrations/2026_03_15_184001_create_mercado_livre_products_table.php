<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mercado_livre_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_id', 50)->unique(); // ID do anúncio no ML
            $table->string('title');
            $table->decimal('current_price', 10, 2);
            $table->decimal('cost_price', 10, 2)->nullable(); // Custo do produto
            $table->decimal('min_margin', 5, 2)->default(10.00); // Margem mínima %
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('taxes', 10, 2)->default(0);
            $table->boolean('auto_reprice')->default(true);
            $table->json('competitors')->nullable(); // IDs de concorrentes
            $table->timestamps();

            $table->index(['user_id', 'auto_reprice']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mercado_livre_products');
    }
};
