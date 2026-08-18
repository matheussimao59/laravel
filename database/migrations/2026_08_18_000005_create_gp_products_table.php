<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->decimal('sell_price', 12, 2)->default(0);
            $table->integer('stock_qty')->default(0);
            $table->decimal('cost_materials', 12, 2)->default(0);
            $table->decimal('cost_labor', 12, 2)->default(0);
            $table->decimal('cost_fixed', 12, 2)->default(0);
            $table->decimal('cost_other', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->string('image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_products');
    }
};
