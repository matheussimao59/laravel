<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->json('sale_channels_json')->nullable()->after('art_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->dropColumn('sale_channels_json');
        });
    }
};