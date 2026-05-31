<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('manual_print_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modelo_id')->nullable()->constrained('modelos')->nullOnDelete();
            $table->string('platform_order_id', 120)->nullable();
            $table->boolean('is_group_order')->default(false);
            $table->unsignedInteger('group_size')->nullable();
            $table->json('values')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 80)->default('Dados Pendente');
            $table->timestamp('saved_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'manual_print_orders_user_status_idx');
            $table->index(['user_id', 'platform_order_id'], 'manual_print_orders_user_platform_idx');
            $table->index(['user_id', 'created_at'], 'manual_print_orders_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_print_orders');
    }
};
