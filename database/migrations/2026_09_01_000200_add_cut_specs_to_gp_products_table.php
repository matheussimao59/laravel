<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->string('cut_shape')->nullable()->after('pricing_type');
            $table->decimal('cut_width', 12, 3)->nullable()->after('cut_shape');
            $table->decimal('cut_height', 12, 3)->nullable()->after('cut_width');
            $table->foreignId('cutting_machine_id')->nullable()->after('cut_height')->constrained('gp_cutting_machines')->nullOnDelete();
            $table->longText('art_image_url')->nullable()->after('cutting_machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('gp_products', function (Blueprint $table) {
            $table->dropForeign(['cutting_machine_id']);
            $table->dropColumn([
                'cut_shape',
                'cut_width',
                'cut_height',
                'cutting_machine_id',
                'art_image_url',
            ]);
        });
    }
};