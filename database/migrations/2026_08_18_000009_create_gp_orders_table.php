<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('gp_quotes')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('gp_clients')->cascadeOnDelete();
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
            $table->string('product_name')->nullable();
            $table->text('description')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->enum('status', [
                'recebido', 'aguardando_arte', 'aprovado', 'em_producao',
                'impressao', 'acabamento', 'revisao', 'pronto', 'entregue', 'cancelado'
            ])->default('recebido');
            $table->enum('payment_status', ['sem_pagamento', 'sinal', 'pago_total', 'parcelado'])->default('sem_pagamento');
            $table->string('payment_method')->nullable();
            $table->text('payment_note')->nullable();
            $table->string('delivery_method')->nullable();
            $table->date('delivery_date')->nullable();
            $table->date('deadline')->nullable();
            $table->string('responsible')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_orders');
    }
};
