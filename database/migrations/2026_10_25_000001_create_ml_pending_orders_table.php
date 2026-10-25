<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_pending_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->string('order_id', 40);
            $table->string('pack_id', 40)->nullable();
            $table->unsignedInteger('pack_size')->nullable();
            $table->unsignedBigInteger('buyer_id')->nullable();
            $table->string('buyer_label')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->string('order_status', 40);
            $table->string('shipment_id', 40)->nullable();
            $table->string('shipment_status', 60)->nullable();
            $table->boolean('is_pending')->nullable();
            $table->json('items');
            $table->string('personalization_name', 120)->nullable();
            $table->string('personalization_age', 40)->nullable();
            $table->string('personalization_status', 40)->default('awaiting');
            $table->string('personalization_source', 20)->default('auto');
            $table->boolean('group_confirmed')->default(false);
            $table->text('observation')->nullable();
            $table->text('manual_note')->nullable();
            $table->text('analysis_note')->nullable();
            $table->json('evidence')->nullable();
            $table->boolean('messages_complete')->default(false);
            $table->uuid('analysis_token')->nullable();
            $table->timestamp('messages_checked_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'seller_id', 'order_id'], 'ml_pending_order_identity');
            $table->index(['user_id', 'seller_id', 'pack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_pending_orders');
    }
};
