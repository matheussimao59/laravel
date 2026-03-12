<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('financial_accounts')) {
            Schema::create('financial_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('type', 40)->default('caixa');
                $table->decimal('current_balance', 14, 2)->default(0);
                $table->string('color', 20)->nullable();
                $table->string('icon', 60)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('financial_categories')) {
            Schema::create('financial_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('type', 20);
                $table->string('color', 20)->nullable();
                $table->string('icon', 60)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('financial_transactions')) {
            Schema::create('financial_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('financial_categories')->nullOnDelete();
                $table->string('type', 20);
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->decimal('amount', 14, 2);
                $table->date('due_date')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('receipt_path')->nullable();
                $table->string('invoice_path')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipping_orders')) {
            Schema::create('shipping_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('import_key', 190);
                $table->string('platform_order_number', 120)->nullable();
                $table->string('ad_name')->nullable();
                $table->string('variation')->nullable();
                $table->longText('image_url')->nullable();
                $table->text('buyer_notes')->nullable();
                $table->text('observations')->nullable();
                $table->integer('product_qty')->default(1);
                $table->string('recipient_name', 190)->nullable();
                $table->string('tracking_number', 120)->nullable();
                $table->string('source_file_name', 190)->nullable();
                $table->date('shipping_deadline')->nullable();
                $table->boolean('packed')->default(false);
                $table->boolean('production_separated')->default(false);
                $table->json('row_raw')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'import_key']);
                $table->index(['user_id', 'tracking_number']);
                $table->index(['user_id', 'shipping_deadline']);
            });
        }

        if (!Schema::hasTable('cover_agenda_items')) {
            Schema::create('cover_agenda_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('order_id', 120);
                $table->longText('front_image');
                $table->longText('back_image');
                $table->boolean('printed')->default(false);
                $table->dateTime('printed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'updated_at']);
                $table->index(['user_id', 'printed', 'updated_at']);
            });
        }

        if (!Schema::hasTable('app_files')) {
            Schema::create('app_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('module', 60);
                $table->unsignedBigInteger('related_id')->nullable();
                $table->string('original_name');
                $table->string('stored_path');
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->timestamps();

                $table->index(['user_id', 'module']);
            });
        }

        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('id', 190)->primary();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->json('config_data')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'updated_at']);
            });
        }

        if (!Schema::hasTable('pricing_materials')) {
            Schema::create('pricing_materials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name', 160);
                $table->decimal('unit_cost', 14, 4)->default(0);
                $table->decimal('cost_per_unit', 14, 4)->default(0);
                $table->string('unit_of_measure', 30)->default('un');
                $table->timestamps();

                $table->index(['user_id', 'name']);
            });
        }

        if (!Schema::hasTable('pricing_products')) {
            Schema::create('pricing_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('product_name', 190);
                $table->longText('product_image_data')->nullable();
                $table->decimal('selling_price', 14, 2)->default(0);
                $table->decimal('base_cost', 14, 2)->default(0);
                $table->decimal('final_margin', 8, 2)->default(0);
                $table->json('materials_json')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('calendar_orders')) {
            Schema::create('calendar_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('order_id', 120);
                $table->longText('image_data');
                $table->boolean('printed')->default(false);
                $table->integer('quantity')->default(1);
                $table->dateTime('printed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'printed', 'created_at']);
            });
        }

        if (!Schema::hasTable('fiscal_settings')) {
            Schema::create('fiscal_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
                $table->string('invoice_series', 20)->default('1');
                $table->string('environment', 20)->default('homologacao');
                $table->string('provider_name', 60)->default('nuvemfiscal');
                $table->string('provider_base_url')->default('https://api.nuvemfiscal.com.br');
                $table->string('cnpj', 30)->nullable();
                $table->string('ie', 30)->nullable();
                $table->string('razao_social')->nullable();
                $table->string('nome_fantasia')->nullable();
                $table->string('regime_tributario', 40)->default('simples_nacional');
                $table->string('email_fiscal')->nullable();
                $table->string('telefone_fiscal', 30)->nullable();
                $table->string('cep', 20)->nullable();
                $table->string('logradouro')->nullable();
                $table->string('numero', 30)->nullable();
                $table->string('complemento')->nullable();
                $table->string('bairro')->nullable();
                $table->string('cidade')->nullable();
                $table->string('uf', 2)->nullable();
                $table->string('certificate_provider_ref')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fiscal_documents')) {
            Schema::create('fiscal_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('order_id');
                $table->string('status', 60)->default('pending');
                $table->string('invoice_number', 60)->nullable();
                $table->string('invoice_series', 20)->nullable();
                $table->string('access_key', 80)->nullable();
                $table->string('provider_ref')->nullable();
                $table->string('xml_url')->nullable();
                $table->string('pdf_url')->nullable();
                $table->text('error_message')->nullable();
                $table->dateTime('issued_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'order_id']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
        Schema::dropIfExists('fiscal_settings');
        Schema::dropIfExists('calendar_orders');
        Schema::dropIfExists('pricing_products');
        Schema::dropIfExists('pricing_materials');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('app_files');
        Schema::dropIfExists('cover_agenda_items');
        Schema::dropIfExists('shipping_orders');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('financial_categories');
        Schema::dropIfExists('financial_accounts');
    }
};
