<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercado_livre_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->string('token_type', 40)->nullable();
            $table->text('scope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_nickname')->nullable();
            $table->string('seller_first_name')->nullable();
            $table->string('seller_last_name')->nullable();
            $table->json('seller_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercado_livre_accounts');
    }
};
