<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gp_product_discount_tiers')) {
            return;
        }

        if (!Schema::hasColumn('gp_product_discount_tiers', 'unit_price')) {
            Schema::table('gp_product_discount_tiers', function (Blueprint $table) {
                $table->decimal('unit_price', 12, 2)->default(0)->after('min_qty');
            });
        }

        Schema::table('gp_product_discount_tiers', function (Blueprint $table) {
            if (Schema::hasColumn('gp_product_discount_tiers', 'discount_type')) {
                $table->dropColumn('discount_type');
            }
            if (Schema::hasColumn('gp_product_discount_tiers', 'discount_value')) {
                $table->dropColumn('discount_value');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('gp_product_discount_tiers')) {
            return;
        }

        Schema::table('gp_product_discount_tiers', function (Blueprint $table) {
            if (Schema::hasColumn('gp_product_discount_tiers', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });

        if (!Schema::hasColumn('gp_product_discount_tiers', 'discount_type')) {
            Schema::table('gp_product_discount_tiers', function (Blueprint $table) {
                $table->string('discount_type')->default('percent')->after('min_qty');
                $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            });
        }
    }
};