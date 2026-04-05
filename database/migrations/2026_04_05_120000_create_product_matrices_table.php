<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_matrices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->longText('image_data');
            $table->string('orientation', 20)->default('portrait');
            $table->string('sheet_size', 20)->default('A4');
            $table->boolean('fill_sheet')->default(false);
            $table->decimal('item_width_mm', 10, 2)->default(210);
            $table->decimal('item_height_mm', 10, 2)->default(297);
            $table->json('fields_json');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_matrices');
    }
};
