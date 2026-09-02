<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_cutting_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->decimal('sheet_width', 12, 2)->default(0);
            $table->decimal('sheet_height', 12, 2)->default(0);
            $table->decimal('usable_width', 12, 2)->default(0);
            $table->decimal('usable_height', 12, 2)->default(0);
            $table->decimal('spacing', 12, 3)->default(0);
            $table->decimal('margin', 12, 3)->default(0);
            $table->string('default_sheet')->nullable()->default('a3');
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_cutting_machines');
    }
};