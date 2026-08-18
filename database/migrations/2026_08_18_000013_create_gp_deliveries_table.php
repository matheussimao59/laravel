<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('gp_orders')->cascadeOnDelete();
            $table->string('client_name')->nullable();
            $table->string('product_name')->nullable();
            $table->string('method')->nullable();
            $table->enum('status', ['pendente', 'saiu', 'entregue', 'retirada'])->default('pendente');
            $table->date('scheduled_date')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_deliveries');
    }
};
