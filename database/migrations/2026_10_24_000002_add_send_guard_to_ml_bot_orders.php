<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_bot_orders', function (Blueprint $table) {
            $table->string('send_status', 30)->nullable();
            $table->text('send_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ml_bot_orders', function (Blueprint $table) {
            $table->dropColumn(['send_status', 'send_reason']);
        });
    }
};
