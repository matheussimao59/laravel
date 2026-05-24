<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopee_order_reports')) {
            return;
        }

        Schema::create('shopee_order_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('import_key', 190);
            $table->unsignedInteger('sequence_number')->nullable();
            $table->string('order_id', 120)->nullable();
            $table->string('refund_id', 120)->nullable();
            $table->string('sku', 190)->nullable();
            $table->string('product_name')->nullable();
            $table->date('order_created_at')->nullable();
            $table->date('payment_completed_at')->nullable();
            $table->string('release_channel', 120)->nullable();
            $table->string('order_type', 120)->nullable();
            $table->string('hot_listing', 30)->nullable();
            $table->decimal('revenue_amount', 14, 2)->default(0);
            $table->decimal('product_price', 14, 2)->default(0);
            $table->string('source_file_name', 190)->nullable();
            $table->json('row_raw')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'import_key']);
            $table->index(['user_id', 'order_id']);
            $table->index(['user_id', 'order_created_at']);
            $table->index(['user_id', 'payment_completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_order_reports');
    }
};
