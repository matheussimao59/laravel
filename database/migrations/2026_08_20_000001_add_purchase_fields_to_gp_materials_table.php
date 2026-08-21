<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_materials', function (Blueprint $table) {
            $table->decimal('total_paid', 12, 2)->default(0)->after('unit_cost');
            $table->decimal('quantity_purchased', 12, 3)->default(0)->after('total_paid');
        });
    }

    public function down(): void
    {
        Schema::table('gp_materials', function (Blueprint $table) {
            $table->dropColumn(['total_paid', 'quantity_purchased']);
        });
    }
};
