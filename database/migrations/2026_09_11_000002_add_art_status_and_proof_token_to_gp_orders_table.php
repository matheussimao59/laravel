<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_orders', function (Blueprint $table) {
            $table->string('art_status', 30)->default('pendente_arte')->after('sticker_qty');
            $table->string('proof_token', 64)->nullable()->after('art_status');
            $table->index('proof_token');
        });
    }

    public function down(): void
    {
        Schema::table('gp_orders', function (Blueprint $table) {
            $table->dropIndex(['proof_token']);
            $table->dropColumn(['art_status', 'proof_token']);
        });
    }
};