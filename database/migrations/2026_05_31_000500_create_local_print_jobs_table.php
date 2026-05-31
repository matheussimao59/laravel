<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('local_print_jobs')) {
            return;
        }

        Schema::create('local_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manual_print_order_id')->nullable()->constrained('manual_print_orders')->nullOnDelete();
            $table->string('printer_name')->nullable();
            $table->string('page_order', 24)->default('normal');
            $table->unsignedInteger('copies')->default(1);
            $table->string('status', 32)->default('pending');
            $table->longText('document_html');
            $table->text('error_message')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at'], 'local_print_jobs_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_print_jobs');
    }
};
