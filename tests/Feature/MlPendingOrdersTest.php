<?php

namespace Tests\Feature;

use App\Models\MercadoLivreAccount;
use App\Models\MlPendingOrder;
use App\Models\User;
use App\Services\MlPendingOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MlPendingOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_orders_are_saved_with_verified_shipping_and_no_external_posts(): void
    {
        $this->account(); $this->fakeMl();
        $this->postJson('/api/integrations/mercado-livre/pending-orders/sync', ['offset' => 0])->assertOk()->assertJsonPath('has_more', false);
        $this->getJson('/api/integrations/mercado-livre/pending-orders')->assertOk()->assertJsonPath('orders.0.is_pending', true);
        $this->postJson('/api/integrations/mercado-livre/pending-orders/sync', ['offset' => 0])->assertOk();
        $this->assertDatabaseCount('ml_pending_orders', 1);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_mila_reads_later_pages_and_only_buyer_messages_without_marking_as_read(): void
    {
        $row = $this->seedPendingOrder(); $this->fakeMl();
        $this->postJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/analyze')->assertOk()->assertJsonPath('complete', true);
        $row->refresh();
        $this->assertSame('Ana Clara', $row->personalization_name);
        $this->assertSame('5 anos', $row->personalization_age);
        $this->assertSame('identified', $row->personalization_status);
        $this->assertSame('Nome: Ana Clara | Idade: 5 anos', $row->observation);
        $this->postJson('/api/integrations/mercado-livre/pending-orders/personalizations', ['ids' => ['1001']])
            ->assertOk()->assertJsonPath('personalizations.1001.ready', true);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/messages/') && (int) $request['offset'] === 50 && $request['mark_as_read'] === 'false');
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_incomplete_conversation_never_authorizes_automatic_filling(): void
    {
        $row = $this->seedPendingOrder(); $this->fakeMl(partial: true);
        $this->postJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/analyze')->assertOk()->assertJsonPath('complete', false);
        $this->assertSame('review', $row->fresh()->personalization_status);
        $this->postJson('/api/integrations/mercado-livre/pending-orders/personalizations', ['ids' => ['1001']])
            ->assertOk()->assertJsonPath('personalizations.1001.ready', false)->assertJsonPath('personalizations.1001.name', null);
    }

    public function test_pack_size_is_checked_even_if_only_one_order_was_imported(): void
    {
        $row = $this->seedPendingOrder(); $this->fakeMl(packSize: 2);
        $this->postJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/analyze')->assertOk();
        $this->assertSame('review', $row->fresh()->personalization_status);
        $this->putJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/personalization', [
            'name' => 'Bruno', 'age' => '8 anos', 'confirmed' => true, 'apply_to_all_items' => false,
        ])->assertStatus(422);
        $this->putJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/personalization', [
            'name' => 'Bruno', 'age' => '8 anos', 'confirmed' => true, 'apply_to_all_items' => true,
        ])->assertOk();
        $this->postJson('/api/integrations/mercado-livre/pending-orders/personalizations', ['ids' => ['1001', '2001']])
            ->assertOk()->assertJsonPath('personalizations.1001.ready', true)->assertJsonPath('personalizations.2001.ready', false);
    }

    public function test_manual_review_survives_a_new_mila_read(): void
    {
        $row = $this->seedPendingOrder(); $this->fakeMl();
        $this->putJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/personalization', [
            'name' => 'Bruno', 'age' => '8 anos', 'note' => 'Tema azul', 'confirmed' => true, 'apply_to_all_items' => false,
        ])->assertOk();
        $this->postJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/analyze')->assertOk();
        $this->assertSame('Bruno', $row->fresh()->personalization_name);
        $this->assertSame('manual', $row->fresh()->personalization_source);
        $this->assertStringContainsString('Tema azul', $row->fresh()->observation);
    }

    public function test_shipped_orders_are_removed_before_reading_their_chat(): void
    {
        $row = $this->seedPendingOrder(); $this->fakeMl(shipmentStatus: 'shipped');
        $this->postJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/analyze')->assertOk()->assertJsonPath('analyzed', false);
        $this->getJson('/api/integrations/mercado-livre/pending-orders')->assertOk()->assertJsonCount(0, 'orders');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/messages/'));
    }

    public function test_other_accounts_cannot_read_review_or_lookup_personalizations(): void
    {
        $row = $this->seedPendingOrder(); $this->account(); Http::fake();
        $this->getJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/messages')->assertNotFound();
        $this->postJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/analyze')->assertNotFound();
        $this->putJson('/api/integrations/mercado-livre/pending-orders/' . $row->id . '/personalization', [])->assertNotFound();
        $this->postJson('/api/integrations/mercado-livre/pending-orders/personalizations', ['ids' => ['1001']])->assertOk()->assertJsonPath('personalizations', []);
        Http::assertNothingSent();
    }

    private function account(): User
    {
        config()->set('services.mercado_livre.base_url', 'https://api.mercadolibre.com');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]); Sanctum::actingAs($user);
        MercadoLivreAccount::create(['user_id' => $user->id, 'access_token' => 'test-token', 'seller_id' => 123]);
        return $user;
    }

    private function seedPendingOrder(): MlPendingOrder
    {
        $user = $this->account();
        return app(MlPendingOrderService::class)->storeSnapshot($user, ['seller_id' => 123, 'order' => $this->order(), 'shipment' => ['status' => 'ready_to_ship']]);
    }

    private function order(): array
    {
        return ['id' => 1001, 'pack_id' => 2001, 'seller' => ['id' => 123], 'buyer' => ['id' => 456], 'status' => 'paid',
            'date_created' => '2026-10-25T12:00:00Z', 'shipping' => ['id' => 9001],
            'order_items' => [['item' => ['id' => 'MLB123', 'title' => 'Kit personalizado', 'seller_sku' => 'tema-azul'], 'quantity' => 1]]];
    }

    private function fakeMl(int $packSize = 1, bool $partial = false, string $shipmentStatus = 'ready_to_ship'): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/orders/search*' => Http::response(['results' => [$this->order()], 'paging' => ['total' => 1]]),
            'https://api.mercadolibre.com/orders/1001' => Http::response($this->order()),
            'https://api.mercadolibre.com/shipments/9001' => Http::response(['id' => 9001, 'sender_id' => 123, 'status' => $shipmentStatus]),
            'https://api.mercadolibre.com/packs/2001' => Http::response(['id' => 2001, 'orders' => array_map(fn ($i) => ['id' => 1000 + $i], range(1, $packSize))]),
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => function (Request $request) use ($partial) {
                $first = array_map(fn ($i) => ['id' => 's' . $i, 'from' => ['user_id' => 123], 'text' => 'Nome: Vendedor | Idade: 99 anos'], range(1, 49));
                $first[] = ['id' => 'buyer-name', 'from' => ['user_id' => 456], 'text' => 'Nome: Ana Clara'];
                $last = [['id' => 'buyer-age', 'from' => ['user_id' => 456], 'text' => 'Idade: 5 anos'], ['id' => 'foreign', 'from' => ['user_id' => 999], 'text' => 'Nome: Pessoa Errada | Idade: 9 anos']];
                return Http::response(['paging' => ['total' => $partial ? 500 : 52, 'limit' => 50], 'messages' => $partial || (int) $request['offset'] === 0 ? $first : $last]);
            },
        ]);
    }
}
