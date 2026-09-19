<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('gp_orders')->cascadeOnDelete();
            $table->string('product_name')->nullable();
            $table->string('product_size')->nullable();
            $table->text('description')->nullable();
            $table->integer('qty')->default(1);
            $table->integer('sticker_qty')->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_order_items');
    }
};