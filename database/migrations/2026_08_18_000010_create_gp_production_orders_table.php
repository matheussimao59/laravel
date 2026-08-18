<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('gp_orders')->cascadeOnDelete();
            $table->string('client_name')->nullable();
            $table->string('product_name')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('total', 12, 2)->default(0);
            $table->enum('stage', ['fila', 'pre_producao', 'impressao', 'acabamento', 'revisao', 'pronto'])->default('fila');
            $table->enum('priority', ['baixa', 'normal', 'alta', 'urgente'])->default('normal');
            $table->date('deadline')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_production_orders');
    }
};
