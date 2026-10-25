<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_pending_orders', function (Blueprint $table) {
            $table->json('personalization_pairs')->nullable()->after('personalization_age');
        });
    }

    public function down(): void
    {
        Schema::table('ml_pending_orders', function (Blueprint $table) {
            if (Schema::hasColumn('ml_pending_orders', 'personalization_pairs')) {
                $table->dropColumn('personalization_pairs');
            }
        });
    }
};
