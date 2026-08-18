<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_product_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->decimal('width_mm', 8, 2)->nullable();
            $table->decimal('height_mm', 8, 2)->nullable();
            $table->string('material')->nullable();
            $table->string('acabamento')->nullable();
            $table->integer('default_qty')->default(100);
            $table->decimal('base_price', 12, 2)->default(0);
            $table->decimal('cost_material', 12, 2)->default(0);
            $table->decimal('cost_labor', 12, 2)->default(0);
            $table->string('image_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_product_templates');
    }
};
