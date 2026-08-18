<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_order_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('gp_orders')->cascadeOnDelete();
            $table->string('filename');
            $table->string('url');
            $table->string('mime_type')->nullable();
            $table->bigInteger('size_bytes')->default(0);
            $table->string('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_order_files');
    }
};
