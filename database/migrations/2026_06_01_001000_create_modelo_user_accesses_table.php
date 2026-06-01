<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('modelo_user_accesses')) {
            Schema::create('modelo_user_accesses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('modelo_id')->constrained('modelos')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['modelo_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modelo_user_accesses');
    }
};
