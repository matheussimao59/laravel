<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_translation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('original_path');
            $table->string('translated_path')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('source_language', 30)->default('spanish');
            $table->string('target_language', 30)->default('english');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('spanish_blocks')->default(0);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_translation_jobs');
    }
};
