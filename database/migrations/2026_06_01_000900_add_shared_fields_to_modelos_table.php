<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modelos', function (Blueprint $table) {
            if (!Schema::hasColumn('modelos', 'is_shared')) {
                $table->boolean('is_shared')->default(false)->after('editor_state')->index();
            }

            if (!Schema::hasColumn('modelos', 'shared_at')) {
                $table->timestamp('shared_at')->nullable()->after('is_shared');
            }
        });
    }

    public function down(): void
    {
        Schema::table('modelos', function (Blueprint $table) {
            if (Schema::hasColumn('modelos', 'shared_at')) {
                $table->dropColumn('shared_at');
            }

            if (Schema::hasColumn('modelos', 'is_shared')) {
                $table->dropIndex(['is_shared']);
                $table->dropColumn('is_shared');
            }
        });
    }
};
