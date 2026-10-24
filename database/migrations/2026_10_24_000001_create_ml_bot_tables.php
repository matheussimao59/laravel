<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabelas do bot Mila (pos-venda Mercado Livre):
     * grupos de anuncios, anuncios marcados por grupo,
     * comandos de pedido rastreados e configuracao da conta.
     */
    public function up(): void
    {
        // Configuracao geral do bot por usuario (uma linha por conta).
        Schema::create('ml_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('start_date')->default('2026-10-23'); // filtra pedidos a partir desta data
            $table->timestamps();

            $table->unique('user_id');
        });

        // Grupos de anuncios: cada grupo tem a propria mensagem padrao.
        Schema::create('ml_bot_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('template')->nullable(); // mensagem personalizada do grupo
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });

        // Anuncios (do Mercado Livre) marcados para o bot, ligados a um grupo.
        Schema::create('ml_bot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('ml_bot_groups')->cascadeOnDelete();
            $table->string('item_id', 50); // ID do anuncio no ML (ex: MLB123...)
            $table->string('title')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('seller_sku', 120)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('item_status', 30)->nullable(); // active/paused...
            $table->timestamps();

            $table->unique(['user_id', 'item_id']); // um anuncio pertence a um grupo por vez
            $table->index(['group_id', 'item_id']);
        });

        // Cada venda elegivel vira um comando rastreado no banco.
        Schema::create('ml_bot_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('ml_bot_groups')->nullOnDelete();
            $table->unsignedBigInteger('order_id'); // id do pedido no ML
            $table->unsignedBigInteger('pack_id')->nullable(); // carrinho (se houver)
            $table->unsignedBigInteger('buyer_id')->nullable();
            $table->string('title')->nullable(); // primeiro item do pedido/carrinho
            $table->string('thumbnail')->nullable();
            $table->string('sku', 150)->nullable();
            $table->decimal('qty', 10, 2)->default(1);
            $table->string('status', 20)->default('pending'); // pending|sent|skipped
            $table->boolean('buyer_replied')->default(false); // cliente ja escreveu no chat
            $table->boolean('seller_messaged')->default(false); // chat ja tem mensagem do vendedor
            $table->unsignedInteger('buyer_messages')->default(0);
            $table->unsignedInteger('seller_messages')->default(0);
            $table->text('sent_message')->nullable(); // texto efetivamente enviado
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('order_date')->nullable();
            $table->timestamp('conversation_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'order_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'pack_id']);
            $table->index(['user_id', 'buyer_replied']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_bot_orders');
        Schema::dropIfExists('ml_bot_items');
        Schema::dropIfExists('ml_bot_groups');
        Schema::dropIfExists('ml_bot_settings');
    }
};
