<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_specific_validation_messages(): void
    {
        User::factory()->create([
            'email' => 'existente@teste.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Matheus',
            'email' => 'existente@teste.com',
            'password' => '123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Este e-mail ja esta cadastrado.')
            ->assertJsonPath('errors.password.0', 'A senha deve ter pelo menos 8 caracteres.');
    }

    public function test_authenticated_user_can_upsert_and_read_app_settings(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/settings/test_setting', [
            'config_data' => [
                'foo' => 'bar',
                'enabled' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('setting.id', 'test_setting')
            ->assertJsonPath('setting.config_data.foo', 'bar');

        $this->getJson('/api/settings/test_setting')
            ->assertOk()
            ->assertJsonPath('setting.config_data.enabled', true);
    }

    public function test_only_admin_can_manage_global_settings(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/settings/global_bala_mockup_config', [
            'config_data' => [
                'template_data' => 'data:image/png;base64,ZmFrZQ==',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('setting.user_id', null)
            ->assertJsonPath('setting.config_data.template_data', 'data:image/png;base64,ZmFrZQ==');

        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/settings/global_bala_mockup_config', [
            'config_data' => [
                'template_data' => 'data:image/png;base64,bm92bw==',
            ],
        ])->assertStatus(403);

        $this->getJson('/api/settings/global_bala_mockup_config')
            ->assertOk()
            ->assertJsonPath('setting.config_data.template_data', 'data:image/png;base64,ZmFrZQ==');
    }

    public function test_admin_can_save_and_read_mercado_livre_config_without_exposing_secret(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/integrations/mercado-livre/config', [
            'client_id' => 'ml-client-id',
            'client_secret' => 'ml-client-secret',
            'redirect_uri' => 'https://unicaprint.com.br/mercado-livre-beta',
        ])
            ->assertOk()
            ->assertJsonPath('config.client_id', 'ml-client-id')
            ->assertJsonPath('config.redirect_uri', 'https://unicaprint.com.br/mercado-livre-beta')
            ->assertJsonPath('config.has_client_secret', true)
            ->assertJsonMissing(['client_secret' => 'ml-client-secret']);

        $stored = DB::table('app_settings')
            ->where('id', 'global_ml_oauth_config')
            ->whereNull('user_id')
            ->first();

        $config = json_decode((string) ($stored?->config_data ?? '{}'), true);

        $this->assertIsArray($config);
        $this->assertNotSame('ml-client-secret', $config['client_secret'] ?? null);
        $this->assertSame('ml-client-secret', Crypt::decryptString((string) $config['client_secret']));

        $this->getJson('/api/integrations/mercado-livre/config')
            ->assertOk()
            ->assertJsonPath('config.client_id', 'ml-client-id')
            ->assertJsonPath('config.configured', true)
            ->assertJsonPath('config.has_client_secret', true)
            ->assertJsonMissing(['client_secret' => 'ml-client-secret']);
    }

    public function test_non_admin_cannot_update_mercado_livre_config(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/integrations/mercado-livre/config', [
            'client_id' => 'ml-client-id',
            'client_secret' => 'ml-client-secret',
            'redirect_uri' => 'https://unicaprint.com.br/mercado-livre-beta',
        ])->assertStatus(403);
    }

    public function test_oauth_token_exchange_falls_back_to_saved_panel_config_when_env_is_empty(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        DB::table('app_settings')->insert([
            'id' => 'global_ml_oauth_config',
            'user_id' => null,
            'config_data' => json_encode([
                'client_id' => 'panel-client-id',
                'client_secret' => Crypt::encryptString('panel-client-secret'),
                'redirect_uri' => 'https://unicaprint.com.br/mercado-livre-beta',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('services.mercado_livre.client_id', '');
        config()->set('services.mercado_livre.client_secret', '');

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'ml-access-token',
                'token_type' => 'bearer',
            ]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/oauth/token', [
            'code' => 'oauth-code',
            'redirect_uri' => 'https://unicaprint.com.br/mercado-livre-beta',
        ])
            ->assertOk()
            ->assertJsonPath('access_token', 'ml-access-token');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadolibre.com/oauth/token'
                && $request['client_id'] === 'panel-client-id'
                && $request['client_secret'] === 'panel-client-secret'
                && $request['code'] === 'oauth-code';
        });
    }

    public function test_oauth_token_exchange_prefers_env_credentials_over_saved_panel_config(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        DB::table('app_settings')->insert([
            'id' => 'global_ml_oauth_config',
            'user_id' => null,
            'config_data' => json_encode([
                'client_id' => 'panel-client-id',
                'client_secret' => Crypt::encryptString('panel-client-secret'),
                'redirect_uri' => 'https://unicaprint.com.br/mercado-livre-beta',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('services.mercado_livre.client_id', 'env-client-id');
        config()->set('services.mercado_livre.client_secret', 'env-client-secret');

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'ml-access-token',
                'token_type' => 'bearer',
            ]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/oauth/token', [
            'code' => 'oauth-code',
            'redirect_uri' => 'https://unicaprint.com.br/mercado-livre-beta',
        ])
            ->assertOk()
            ->assertJsonPath('access_token', 'ml-access-token');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadolibre.com/oauth/token'
                && $request['client_id'] === 'env-client-id'
                && $request['client_secret'] === 'env-client-secret'
                && $request['code'] === 'oauth-code';
        });
    }

    public function test_authenticated_user_can_create_and_list_pricing_materials(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pricing/materials', [
            'name' => 'Papel Offset 180g',
            'unit_cost' => 3.75,
            'unit_of_measure' => 'folha',
        ])
            ->assertCreated()
            ->assertJsonPath('material.name', 'Papel Offset 180g')
            ->assertJsonPath('material.unit_cost', 3.75);

        $this->getJson('/api/pricing/materials')
            ->assertOk()
            ->assertJsonCount(1, 'materials')
            ->assertJsonPath('materials.0.unit_of_measure', 'folha');
    }

    public function test_authenticated_user_can_create_and_list_cover_agenda_items(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $imageData = 'data:image/png;base64,' . base64_encode('fake-image');

        $this->postJson('/api/cover-agenda', [
            'order_id' => '66',
            'front_image' => $imageData,
            'back_image' => $imageData,
            'printed' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('item.order_id', '66')
            ->assertJsonPath('item.printed', false)
            ->assertJsonPath('item.front_image', $imageData)
            ->assertJsonPath('item.back_image', $imageData);

        $this->getJson('/api/cover-agenda?limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.order_id', '66')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.limit', 10)
            ->assertJsonPath('meta.offset', 0);

        $this->getJson('/api/cover-agenda?limit=10&include_images=0')
            ->assertOk()
            ->assertJsonPath('items.0.front_image', null)
            ->assertJsonPath('items.0.back_image', null)
            ->assertJsonPath('items.0.has_front_image', true)
            ->assertJsonPath('items.0.has_back_image', true);
    }

    public function test_cover_agenda_index_supports_offset_pagination(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        foreach (['100', '101', '102'] as $index => $orderId) {
            DB::table('cover_agenda_items')->insert([
                'user_id' => $user->id,
                'order_id' => $orderId,
                'front_image' => 'data:image/png;base64,' . base64_encode("front-{$index}"),
                'back_image' => 'data:image/png;base64,' . base64_encode("back-{$index}"),
                'printed' => false,
                'printed_at' => null,
                'created_at' => now()->subMinutes(3 - $index),
                'updated_at' => now()->subMinutes(3 - $index),
            ]);
        }

        $this->getJson('/api/cover-agenda?limit=2&offset=0&include_images=0')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.offset', 0);

        $this->getJson('/api/cover-agenda?limit=2&offset=2&include_images=0')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.offset', 2);
    }

    public function test_authenticated_user_can_mark_cover_agenda_item_as_printed(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $coverId = DB::table('cover_agenda_items')->insertGetId([
            'user_id' => $user->id,
            'order_id' => '88',
            'front_image' => 'data:image/png;base64,' . base64_encode('front'),
            'back_image' => 'data:image/png;base64,' . base64_encode('back'),
            'printed' => false,
            'printed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchJson("/api/cover-agenda/{$coverId}/printed", [
            'printed' => true,
            'printed_at' => '2026-03-17T10:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('item.id', (string) $coverId)
            ->assertJsonPath('item.printed', true)
            ->assertJsonPath('item.printed_at', '2026-03-17T10:00:00Z');

        $this->assertDatabaseHas('cover_agenda_items', [
            'id' => $coverId,
            'printed' => true,
            'printed_at' => '2026-03-17T10:00:00Z',
        ]);
    }

    public function test_scanner_can_load_linked_cover_and_calendar_artwork(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $shippingOrderId = DB::table('shipping_orders')->insertGetId([
            'user_id' => $user->id,
            'import_key' => 'scan-art-1',
            'platform_order_number' => '123456',
            'ad_name' => 'Agenda Pet',
            'product_qty' => 1,
            'tracking_number' => 'BR123456789',
            'packed' => false,
            'production_separated' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cover_agenda_items')->insert([
            'user_id' => $user->id,
            'order_id' => 'Pedido #123456',
            'front_image' => 'data:image/png;base64,' . base64_encode('front'),
            'back_image' => 'data:image/png;base64,' . base64_encode('back'),
            'printed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('calendar_orders')->insert([
            'user_id' => $user->id,
            'order_id' => 'ID: #123456',
            'image_data' => 'data:image/png;base64,' . base64_encode('calendar'),
            'quantity' => 2,
            'printed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/shipping/orders/{$shippingOrderId}/artwork")
            ->assertOk()
            ->assertJsonPath('artwork.cover_agenda.order_id', 'Pedido #123456')
            ->assertJsonPath('artwork.calendar.order_id', 'ID: #123456')
            ->assertJsonPath('artwork.calendar.quantity', 2);
    }

    public function test_admin_can_import_shopee_rows_filter_by_month_and_auto_create_unique_products(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/shopee/orders/import', [
            'rows' => [
                [
                    'import_key' => 'shopee-1',
                    'sequence_number' => 1,
                    'order_id' => '2410080W8BJV43',
                    'sku' => '20677569790',
                    'product_name' => '100 Cartao de agradecimento ao cliente com bala Personalizada',
                    'order_created_at' => '2024-10-07',
                    'payment_completed_at' => '2024-11-16',
                    'release_channel' => 'Carteira do vendedor',
                    'order_type' => 'Pedido normal',
                    'hot_listing' => 'NO',
                    'revenue_amount' => 31.72,
                    'product_price' => 47.00,
                    'row_raw' => ['source' => 'xlsx'],
                ],
                [
                    'import_key' => 'shopee-order-no-product',
                    'sequence_number' => 0,
                    'order_id' => '24101196Q9UTGD',
                    'sku' => null,
                    'product_name' => '-',
                    'order_created_at' => '2024-10-11',
                    'payment_completed_at' => '2024-11-08',
                    'release_channel' => 'Carteira do vendedor',
                    'order_type' => 'Pedido normal',
                    'hot_listing' => 'NO',
                    'revenue_amount' => 23.28,
                    'product_price' => 35.90,
                    'row_raw' => ['source' => 'xlsx'],
                ],
                [
                    'import_key' => 'shopee-2',
                    'sequence_number' => 2,
                    'order_id' => '24101196Q9UTGD',
                    'sku' => '20677569790',
                    'product_name' => '100 Cartao de agradecimento ao cliente com bala Personalizada',
                    'order_created_at' => '2024-10-11',
                    'payment_completed_at' => '2024-11-08',
                    'release_channel' => 'Carteira do vendedor',
                    'order_type' => 'Pedido normal',
                    'hot_listing' => 'NO',
                    'revenue_amount' => 0,
                    'product_price' => 47.00,
                    'row_raw' => ['source' => 'xlsx'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('stats.inserted', 1)
            ->assertJsonPath('stats.skipped_non_positive', 1)
            ->assertJsonPath('stats.products_created', 1);

        $this->getJson('/api/shopee/orders?year=2024&month=10')
            ->assertOk()
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.received_total', 31.72)
            ->assertJsonPath('summary.unpaid_total', 0)
            ->assertJsonPath('rows.0.linked_product.product_name', '100 Cartao de agradecimento ao cliente com bala Personalizada')
            ->assertJsonPath('products.0.product_name', '100 Cartao de agradecimento ao cliente com bala Personalizada');

        $this->assertDatabaseCount('shopee_products', 1);
    }

    public function test_admin_can_import_shopee_rows_with_negative_product_price_adjustments(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/shopee/orders/import', [
            'rows' => [
                [
                    'import_key' => 'shopee-negative-1',
                    'sequence_number' => 378,
                    'order_id' => '241005QW5JWV1Q',
                    'sku' => '20677569790',
                    'product_name' => '100 Balas Personalizadas Outubro Rosa Mimo Para Cliente, Festa, Lembrancinha Personalizada',
                    'order_created_at' => '2024-10-05',
                    'payment_completed_at' => '2024-11-01',
                    'release_channel' => 'Carteira do vendedor',
                    'order_type' => 'Pedido normal',
                    'hot_listing' => 'NO',
                    'revenue_amount' => -0.29,
                    'product_price' => -0.29,
                    'row_raw' => ['source' => 'csv'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('stats.inserted', 0)
            ->assertJsonPath('stats.skipped_non_positive', 1)
            ->assertJsonPath('stats.products_created', 0);

        $this->assertDatabaseMissing('shopee_products', [
            'user_id' => $user->id,
            'product_name' => '100 Balas Personalizadas Outubro Rosa Mimo Para Cliente, Festa, Lembrancinha Personalizada',
        ]);
    }

    public function test_admin_import_ignores_existing_shopee_product_titles(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $productId = DB::table('shopee_products')->insertGetId([
            'user_id' => $user->id,
            'product_name' => 'Produto Existente Shopee',
            'original_price' => 15.50,
            'production_cost' => 4.25,
            'materials_json' => json_encode(['items' => [['name' => 'Papel', 'cost' => 1.2]]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/shopee/orders/import', [
            'rows' => [
                [
                    'import_key' => 'shopee-existing-product-1',
                    'sequence_number' => 1,
                    'order_id' => 'PED-EXISTENTE-1',
                    'sku' => 'SKU-1',
                    'product_name' => 'Produto Existente Shopee',
                    'order_created_at' => '2024-10-07',
                    'payment_completed_at' => '2024-11-16',
                    'release_channel' => 'Carteira do vendedor',
                    'order_type' => 'Pedido normal',
                    'hot_listing' => 'NO',
                    'revenue_amount' => 31.72,
                    'product_price' => 99.90,
                    'row_raw' => ['source' => 'xlsx'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('stats.inserted', 1)
            ->assertJsonPath('stats.products_created', 0)
            ->assertJsonPath('stats.products_updated', 0);

        $this->assertDatabaseHas('shopee_products', [
            'id' => $productId,
            'user_id' => $user->id,
            'product_name' => 'Produto Existente Shopee',
            'original_price' => 15.50,
            'production_cost' => 4.25,
        ]);

        $this->assertDatabaseCount('shopee_products', 1);
    }

    public function test_shopee_summary_marks_sales_without_registered_product_cost(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        DB::table('shopee_products')->insert([
            [
                'user_id' => $user->id,
                'product_name' => 'Produto Sem Custo',
                'original_price' => 120,
                'production_cost' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'product_name' => 'Produto Com Custo',
                'original_price' => 140,
                'production_cost' => 15,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('shopee_order_reports')->insert([
            [
                'user_id' => $user->id,
                'import_key' => 'missing-cost-order',
                'sequence_number' => 1,
                'order_id' => 'MC-1',
                'product_name' => 'Produto Sem Custo',
                'order_created_at' => '2024-10-10',
                'revenue_amount' => 100,
                'product_price' => 120,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'import_key' => 'with-cost-order',
                'sequence_number' => 2,
                'order_id' => 'WC-1',
                'product_name' => 'Produto Com Custo',
                'order_created_at' => '2024-10-11',
                'revenue_amount' => 80,
                'product_price' => 140,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson('/api/shopee/orders?year=2024&month=10')
            ->assertOk()
            ->assertJsonPath('summary.profit_total', 65)
            ->assertJsonPath('summary.missing_cost_rows', 1)
            ->assertJsonPath('summary.missing_cost_total', 100)
            ->assertJsonPath('rows.0.product_name', 'Produto Com Custo')
            ->assertJsonPath('rows.0.estimated_net_profit', 65)
            ->assertJsonPath('rows.1.product_name', 'Produto Sem Custo')
            ->assertJsonPath('rows.1.estimated_net_profit', null);
    }

    public function test_admin_can_delete_all_shopee_orders_for_a_year(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        DB::table('shopee_order_reports')->insert([
            [
                'user_id' => $user->id,
                'import_key' => 'del-2024-1',
                'sequence_number' => 1,
                'order_id' => '2024A',
                'product_name' => 'Produto 2024',
                'order_created_at' => '2024-10-01',
                'revenue_amount' => 10,
                'product_price' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'import_key' => 'del-2025-1',
                'sequence_number' => 2,
                'order_id' => '2025A',
                'product_name' => 'Produto 2025',
                'order_created_at' => '2025-01-10',
                'revenue_amount' => 15,
                'product_price' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->deleteJson('/api/shopee/orders/by-year', [
            'year' => 2024,
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('year', 2024);

        $this->assertDatabaseMissing('shopee_order_reports', [
            'user_id' => $user->id,
            'import_key' => 'del-2024-1',
        ]);
        $this->assertDatabaseHas('shopee_order_reports', [
            'user_id' => $user->id,
            'import_key' => 'del-2025-1',
        ]);
    }

    public function test_admin_can_bulk_delete_shopee_products(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $firstId = DB::table('shopee_products')->insertGetId([
            'user_id' => $user->id,
            'product_name' => 'Produto A',
            'original_price' => 12,
            'production_cost' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondId = DB::table('shopee_products')->insertGetId([
            'user_id' => $user->id,
            'product_name' => 'Produto B',
            'original_price' => 20,
            'production_cost' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/shopee/products/bulk-delete', [
            'ids' => [$firstId, $secondId],
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseMissing('shopee_products', [
            'id' => $firstId,
        ]);
        $this->assertDatabaseMissing('shopee_products', [
            'id' => $secondId,
        ]);
    }

    public function test_admin_can_bulk_delete_shopee_orders(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $firstId = DB::table('shopee_order_reports')->insertGetId([
            'user_id' => $user->id,
            'import_key' => 'bulk-order-1',
            'sequence_number' => 1,
            'order_id' => 'ORDER-1',
            'product_name' => 'Produto A',
            'order_created_at' => '2024-10-01',
            'revenue_amount' => 20,
            'product_price' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondId = DB::table('shopee_order_reports')->insertGetId([
            'user_id' => $user->id,
            'import_key' => 'bulk-order-2',
            'sequence_number' => 2,
            'order_id' => 'ORDER-2',
            'product_name' => 'Produto B',
            'order_created_at' => '2024-10-02',
            'revenue_amount' => 15,
            'product_price' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/shopee/orders/bulk-delete', [
            'ids' => [$firstId, $secondId],
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseMissing('shopee_order_reports', [
            'id' => $firstId,
        ]);
        $this->assertDatabaseMissing('shopee_order_reports', [
            'id' => $secondId,
        ]);
    }

    public function test_shopee_chart_can_compare_multiple_years(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        DB::table('shopee_products')->insert([
            [
                'user_id' => $user->id,
                'product_name' => 'Produto A',
                'original_price' => 120,
                'production_cost' => 40,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'product_name' => 'Produto B',
                'original_price' => 180,
                'production_cost' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('shopee_order_reports')->insert([
            [
                'user_id' => $user->id,
                'import_key' => 'chart-2024-jan',
                'sequence_number' => 1,
                'order_id' => 'CH-1',
                'product_name' => 'Produto A',
                'order_created_at' => '2024-01-10',
                'revenue_amount' => 100,
                'product_price' => 120,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'import_key' => 'chart-2024-feb',
                'sequence_number' => 2,
                'order_id' => 'CH-2',
                'product_name' => 'Produto A',
                'order_created_at' => '2024-02-10',
                'revenue_amount' => 200,
                'product_price' => 220,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'import_key' => 'chart-2025-jan',
                'sequence_number' => 3,
                'order_id' => 'CH-3',
                'product_name' => 'Produto B',
                'order_created_at' => '2025-01-10',
                'revenue_amount' => 150,
                'product_price' => 180,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson('/api/shopee/orders?chart_years=2024,2025')
            ->assertOk()
            ->assertJsonPath('chart.available_years.0', 2024)
            ->assertJsonPath('chart.available_years.1', 2025)
            ->assertJsonPath('chart.series.0.year', 2024)
            ->assertJsonPath('chart.series.0.values.0', 60)
            ->assertJsonPath('chart.series.0.values.1', 160)
            ->assertJsonPath('chart.series.1.year', 2025)
            ->assertJsonPath('chart.series.1.values.0', 125);
    }

    public function test_authenticated_user_can_exchange_ml_oauth_token_and_sync_orders(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        config()->set('services.mercado_livre.client_id', 'ml-client');
        config()->set('services.mercado_livre.client_secret', 'ml-secret');

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'ml-access-token',
                'token_type' => 'bearer',
            ]),
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 123,
                'nickname' => 'unica.print',
            ]),
            'https://api.mercadolibre.com/orders/search*' => Http::response([
                'results' => [[
                    'id' => 9001,
                    'status' => 'paid',
                    'date_created' => '2026-03-12T08:00:00Z',
                    'order_items' => [[
                        'item' => [
                            'id' => 'ITEM-1',
                            'title' => 'Planner Permanente',
                        ],
                        'quantity' => 2,
                    ]],
                    'shipping' => [
                        'id' => 777,
                    ],
                ]],
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([
                [
                    'body' => [
                        'id' => 'ITEM-1',
                        'thumbnail' => 'https://img.ml/item-1.jpg',
                    ],
                ],
            ]),
            'https://api.mercadolibre.com/shipments/777' => Http::response([
                'id' => 777,
                'status' => 'ready_to_ship',
                'substatus' => 'printed',
            ]),
            'https://api.mercadolibre.com/shipments/777/costs' => Http::response([
                [
                    'payer_type' => 'seller',
                    'amount' => 12.5,
                ],
            ]),
            'https://api.mercadolibre.com/shipments/777/payments' => Http::response([]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/oauth/token', [
            'code' => 'oauth-code',
            'redirect_uri' => 'https://app.test/mercado-livre',
            'code_verifier' => 'pkce-verifier',
        ])
            ->assertOk()
            ->assertJsonPath('access_token', 'ml-access-token');

        $this->postJson('/api/integrations/mercado-livre/sync', [
            'access_token' => 'ml-access-token',
            'from_date' => '2026-03-01T00:00:00Z',
            'to_date' => '2026-03-12T23:59:59Z',
            'include_payments_details' => false,
            'include_shipments_details' => true,
            'max_pages' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('seller.id', 123)
            ->assertJsonPath('orders.0.id', 9001)
            ->assertJsonPath('orders.0.order_items.0.item.thumbnail', 'https://img.ml/item-1.jpg')
            ->assertJsonPath('orders.0.shipping_cost_seller', 12.5);
    }

    public function test_mercado_livre_service_updates_item_price_using_put_request(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLB123' => Http::response([
                'id' => 'MLB123',
                'price' => 99.9,
            ]),
        ]);

        $service = app(\App\Services\MercadoLivreService::class);

        $this->assertTrue($service->updateItemPrice('MLB123', 99.9, 'ml-access-token'));

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://api.mercadolibre.com/items/MLB123'
                && $request->hasHeader('Authorization', 'Bearer ml-access-token')
                && $request['price'] === 99.9;
        });
    }

    public function test_authenticated_user_can_emit_and_check_fiscal_status(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        config()->set('services.fiscal_provider.token', 'provider-token');
        config()->set('services.fiscal_provider.base_url', 'https://api.nuvemfiscal.com.br');
        config()->set('services.fiscal_provider.issue_path', '/v1/nfe');
        config()->set('services.fiscal_provider.status_path_template', '/v1/nfe/{id}');

        Http::fake([
            'https://api.nuvemfiscal.com.br/v1/nfe' => Http::response([
                'id' => 'nf-123',
                'status' => 'approved',
                'numero' => '101',
                'chave' => 'CHAVE-ABC',
                'xml_url' => 'https://provider/xml/101',
                'pdf_url' => 'https://provider/pdf/101',
            ]),
            'https://api.nuvemfiscal.com.br/v1/nfe/nf-123' => Http::response([
                'status' => 'approved',
                'numero' => '101',
                'chave_acesso' => 'CHAVE-ABC',
                'url_xml' => 'https://provider/xml/101',
                'url_pdf' => 'https://provider/pdf/101',
            ]),
        ]);

        $emitter = [
            'cnpj' => '12345678000190',
            'razao_social' => 'Unica Print LTDA',
            'regime_tributario' => 'simples_nacional',
            'logradouro' => 'Rua A',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'Sao Paulo',
            'uf' => 'SP',
            'cep' => '01001000',
            'certificate_provider_ref' => 'cert-1',
        ];

        $this->postJson('/api/integrations/fiscal/emit', [
            'order_id' => 1234,
            'order_total' => 199.9,
            'invoice_series' => '1',
            'environment' => 'homologacao',
            'provider_name' => 'nuvemfiscal',
            'order_title' => 'Planner',
            'buyer_name' => 'Cliente Teste',
            'emitter' => $emitter,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'authorized')
            ->assertJsonPath('provider_ref', 'nf-123')
            ->assertJsonPath('invoice_number', '101');

        $this->postJson('/api/integrations/fiscal/status', [
            'provider_ref' => 'nf-123',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'authorized')
            ->assertJsonPath('access_key', 'CHAVE-ABC')
            ->assertJsonPath('pdf_url', 'https://provider/pdf/101');
    }
}
