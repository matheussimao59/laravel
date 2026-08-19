<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_product_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('gp_products')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('gp_materials')->cascadeOnDelete();
            $table->decimal('qty_needed', 12, 4)->default(1);
            $table->decimal('cost_override', 12, 2)->nullable()->comment('Custo unitario override (null = usar unit_cost do material)');
            $table->timestamps();

            $table->unique(['product_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_product_materials');
    }
};