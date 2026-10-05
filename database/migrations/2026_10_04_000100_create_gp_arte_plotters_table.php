<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_arte_plotters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('machine')->nullable();
            $table->string('sheet_format', 20)->default('a4');
            $table->string('orientation', 20)->default('retrato');
            $table->decimal('sheet_width_mm', 10, 2)->default(0);
            $table->decimal('sheet_height_mm', 10, 2)->default(0);
            $table->json('items')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_arte_plotters');
    }
};
