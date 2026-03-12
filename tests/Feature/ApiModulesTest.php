<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
