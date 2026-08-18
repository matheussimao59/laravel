<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->enum('type', ['fixa', 'variavel'])->default('variavel');
            $table->string('category')->nullable();
            $table->date('date');
            $table->boolean('recurring')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_expenses');
    }
};
