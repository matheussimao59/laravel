<?php

namespace Tests\Feature;

use App\Models\GpMaterial;
use App\Models\GpProduct;
use App\Models\MercadoLivreAccount;
use App\Models\MlSaleProduct;
use App\Models\User;
use App\Services\MercadoLivreSaleProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MercadoLivreSaleProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_sync_imports_products_once_without_writing_to_ml(): void
    {
        $this->account();
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadolibre.com/orders/search*' => Http::response(['results' => [$this->order()]])]);
        $payload = ['from_date' => '2026-10-01T00:00:00Z', 'max_pages' => 1, 'include_item_thumbnails' => false];

        $this->postJson('/api/integrations/mercado-livre/sync', $payload)
            ->assertOk()->assertJsonPath('products_import.created', 1)
            ->assertJsonPath('orders.0.order_items.0.local_product.cost_configured', false)
            ->assertJsonPath('orders.0.order_items.0.local_product.unit_cost', null);
        $this->postJson('/api/integrations/mercado-livre/sync', $payload)
            ->assertOk()->assertJsonPath('products_import.created', 0);

        $this->assertDatabaseCount('ml_sale_products', 1);
        $this->assertDatabaseCount('gp_products', 1);
        $this->assertFalse(GpProduct::firstOrFail()->active);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_new_sales_do_not_overwrite_local_pricing_materials_or_fees(): void
    {
        $user = $this->account();
        $catalog = app(MercadoLivreSaleProductService::class);
        $catalog->importOrders($user, 123, [$this->order()]);
        $record = MlSaleProduct::firstOrFail();
        $material = GpMaterial::create(['user_id' => $user->id, 'name' => 'Papel', 'unit_cost' => 2]);
        $fees = ['channels' => [['id' => 'ml', 'name' => 'Mercado Livre', 'percent' => 16, 'fixed' => 6.5]]];

        $this->putJson('/api/grafica-plus/products/' . $record->gp_product_id, [
            'sell_price' => 120, 'cost_materials' => 6, 'cost_labor' => 1, 'cost_fixed' => 1, 'cost_other' => 1,
            'sale_channels_json' => $fees, 'description' => 'Descricao local preservada.',
            'materials' => [['material_id' => $material->id, 'qty_needed' => 3, 'cost_override' => null]],
        ])->assertOk();

        $newSale = $this->order(1002, null, '2026-10-25T12:00:00Z', 90);
        $catalog->importOrders($user, 123, [$newSale, $this->order()]);
        $record->refresh();
        $this->assertNotNull($record->costs_configured_at);
        $this->assertSame(90.0, (float) $record->last_sale_price);
        $this->assertSame(120.0, (float) $record->product->sell_price);
        $this->assertSame($fees, $record->product->sale_channels_json);
        $this->assertSame(1, $record->product->materials()->count());

        // O custo atual da materia-prima passa a valer sem duplicar a ficha.
        $material->update(['unit_cost' => 3]);
        $orders = $catalog->attachCosts($user, 123, [$newSale]);
        $this->assertSame(12.0, $orders[0]['order_items'][0]['local_product']['unit_cost']);
        $this->assertDatabaseCount('gp_products', 1);
    }

    public function test_variations_and_accounts_have_independent_cost_records(): void
    {
        $user = $this->account();
        $catalog = app(MercadoLivreSaleProductService::class);
        $orders = [$this->order(1001, 111), $this->order(1002, 222)];
        $catalog->importOrders($user, 123, $orders);
        $first = MlSaleProduct::where('variation_id', '111')->firstOrFail();
        $first->product->update(['cost_materials' => 3]);
        $first->update(['costs_configured_at' => now()]);
        $attached = $catalog->attachCosts($user, 123, $orders);
        $this->assertSame(3.0, $attached[0]['order_items'][0]['local_product']['unit_cost']);
        $this->assertNull($attached[1]['order_items'][0]['local_product']['unit_cost']);

        $otherUser = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $catalog->importOrders($otherUser, 123, [$this->order(1001, 111)]);
        $this->assertDatabaseCount('ml_sale_products', 3);
        Sanctum::actingAs($otherUser);
        $this->getJson('/api/integrations/mercado-livre/sale-products')->assertOk()->assertJsonCount(1, 'products');
        Http::fake();
        $this->postJson('/api/integrations/mercado-livre/sale-products/' . $first->id . '/refresh')->assertNotFound();
        $this->putJson('/api/grafica-plus/products/' . $first->gp_product_id, ['cost_labor' => 999])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_refresh_imports_photos_and_description_without_overwriting_costs(): void
    {
        $user = $this->account();
        app(MercadoLivreSaleProductService::class)->importOrders($user, 123, [$this->order()]);
        $record = MlSaleProduct::firstOrFail();
        $record->product->update(['sell_price' => 150, 'cost_labor' => 15, 'description' => 'Anotacao local.']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/items/MLB123' => Http::response([
                'id' => 'MLB123', 'seller_id' => 123, 'title' => 'Titulo atual do anuncio', 'price' => 110,
                'pictures' => [['id' => 'A', 'secure_url' => 'https://img.test/a.jpg'], ['id' => 'B', 'url' => 'http://img.test/b.jpg']],
            ]),
            'https://api.mercadolibre.com/items/MLB123/description' => Http::response(['plain_text' => 'Descricao importada.']),
        ]);

        $this->postJson('/api/integrations/mercado-livre/sale-products/' . $record->id . '/refresh')
            ->assertOk()->assertJsonPath('product.ml_sale_product.description', 'Descricao importada.')
            ->assertJsonPath('product.ml_sale_product.pictures.1', 'https://img.test/b.jpg');
        $record->refresh();
        $this->assertSame(110.0, (float) $record->listing_price);
        $this->assertSame(150.0, (float) $record->product->sell_price);
        $this->assertSame(15.0, (float) $record->product->cost_labor);
        $this->assertSame('Anotacao local.', $record->product->description);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_unknown_cost_differs_from_intentionally_saved_zero_cost(): void
    {
        $user = $this->account();
        $catalog = app(MercadoLivreSaleProductService::class);
        $catalog->importOrders($user, 123, [$this->order()]);
        $record = MlSaleProduct::firstOrFail();
        $before = $catalog->attachCosts($user, 123, [$this->order()]);
        $this->assertNull($before[0]['order_items'][0]['local_product']['unit_cost']);
        $this->putJson('/api/grafica-plus/products/' . $record->gp_product_id, [
            'cost_materials' => 0, 'cost_labor' => 0, 'cost_fixed' => 0, 'cost_other' => 0, 'materials' => [], 'confirm_zero_cost' => true,
        ])->assertOk();
        $after = $catalog->attachCosts($user, 123, [$this->order()]);
        $this->assertTrue($after[0]['order_items'][0]['local_product']['cost_configured']);
        $this->assertSame(0.0, $after[0]['order_items'][0]['local_product']['unit_cost']);
    }

    public function test_cancelled_unpaid_and_foreign_sales_are_not_imported(): void
    {
        $user = $this->account();
        $cancelled = $this->order(); $cancelled['status'] = 'cancelled';
        $unpaid = $this->order(); $unpaid['status'] = 'payment_required';
        $foreign = $this->order(); $foreign['seller']['id'] = 999;
        app(MercadoLivreSaleProductService::class)->importOrders($user, 123, [$cancelled, $unpaid, $foreign]);
        $this->assertDatabaseCount('ml_sale_products', 0);
        $this->assertDatabaseCount('gp_products', 0);
    }

    public function test_saving_unconfirmed_zero_cost_remains_pending(): void
    {
        $user = $this->account();
        app(MercadoLivreSaleProductService::class)->importOrders($user, 123, [$this->order()]);
        $record = MlSaleProduct::firstOrFail();
        $this->putJson('/api/grafica-plus/products/' . $record->gp_product_id, ['cost_materials' => 0, 'materials' => []])->assertOk();
        $this->assertNull($record->fresh()->costs_configured_at);
    }

    public function test_cost_sheet_rejects_materials_from_another_user(): void
    {
        $user = $this->account();
        app(MercadoLivreSaleProductService::class)->importOrders($user, 123, [$this->order()]);
        $record = MlSaleProduct::firstOrFail();
        $other = User::factory()->create();
        $material = GpMaterial::create(['user_id' => $other->id, 'name' => 'Privado', 'unit_cost' => 50]);
        $this->putJson('/api/grafica-plus/products/' . $record->gp_product_id, [
            'materials' => [['material_id' => $material->id, 'qty_needed' => 1]],
        ])->assertStatus(422);
        $this->assertNull($record->fresh()->costs_configured_at);
        $this->assertSame(0, $record->product->materials()->count());
    }

    public function test_an_older_order_does_not_replace_last_price_across_timezones(): void
    {
        $user = $this->account();
        $catalog = app(MercadoLivreSaleProductService::class);
        $catalog->importOrders($user, 123, [$this->order(1001, null, '2026-10-24T12:00:00-03:00', 100)]);
        $catalog->importOrders($user, 123, [$this->order(1000, null, '2026-10-24T13:30:00Z', 20)]);
        $this->assertSame(100.0, (float) MlSaleProduct::firstOrFail()->last_sale_price);
    }

    private function account(): User
    {
        config()->set('services.mercado_livre.base_url', 'https://api.mercadolibre.com');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($user);
        MercadoLivreAccount::create(['user_id' => $user->id, 'access_token' => 'test-token', 'seller_id' => 123]);

        return $user;
    }

    private function order(int $id = 1001, ?int $variation = null, string $date = '2026-10-24T12:00:00Z', float $price = 100): array
    {
        return [
            'id' => $id, 'status' => 'paid', 'seller' => ['id' => 123], 'date_created' => $date,
            'order_items' => [[
                'item' => ['id' => 'MLB123', 'variation_id' => $variation, 'title' => 'Produto vendido', 'seller_sku' => 'SKU-1', 'thumbnail' => 'https://img.test/initial.jpg'],
                'quantity' => 2, 'unit_price' => $price,
            ]],
        ];
    }
}
