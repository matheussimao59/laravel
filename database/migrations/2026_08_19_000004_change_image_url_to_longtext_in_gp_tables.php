<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_categories', function (Blueprint $table) {
            $table->longText('image_url')->nullable()->change();
        });

        Schema::table('gp_products', function (Blueprint $table) {
            $table->longText('image_url')->nullable()->change();
        });

        Schema::table('gp_materials', function (Blueprint $table) {
            $table->longText('image_url')->nullable()->change();
        });

        Schema::table('gp_product_templates', function (Blueprint $table) {
            $table->longText('image_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('gp_categories', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
        });

        Schema::table('gp_products', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
        });

        Schema::table('gp_materials', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
        });

        Schema::table('gp_product_templates', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
        });
    }
};
