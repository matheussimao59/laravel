<?php

namespace Tests\Feature;

use App\Models\MercadoLivreAccount;
use App\Models\MlBotGroup;
use App\Models\MlBotItem;
use App\Models\MlBotOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MercadoLivreBotTest extends TestCase
{
    use RefreshDatabase;

    public static function sellerSources(): array
    {
        return [
            'cached seller' => [true],
            'seller from users/me' => [false],
        ];
    }

    #[DataProvider('sellerSources')]
    public function test_scan_uses_synced_seller_to_check_pack_conversation(bool $cachedSeller): void
    {
        $user = $this->createBotUser($cachedSeller);
        $orders = [$this->order(1001, 2001), $this->order(1002, 2001)];

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response(['id' => 123]),
            'https://api.mercadolibre.com/orders/search*' => Http::response(['results' => $orders]),
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response([
                'conversation' => [
                    ['from' => ['user_id' => 456], 'message' => 'Ola', 'date' => '2026-10-24T12:00:00Z'],
                    ['from' => ['user_id' => 123], 'message' => 'Bom dia', 'date' => '2026-10-24T12:01:00Z'],
                ],
            ]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/scan', [
            'start_date' => '2026-10-23',
            'max_pages' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('summary.scanned_orders', 2)
            ->assertJsonPath('summary.eligible', 1)
            ->assertJsonPath('summary.conversation_checks', 1)
            ->assertJsonPath('counts.replied', 2)
            ->assertJsonCount(2, 'orders');

        foreach ([1001, 1002] as $orderId) {
            $this->assertDatabaseHas('ml_bot_orders', [
                'user_id' => $user->id,
                'order_id' => $orderId,
                'pack_id' => 2001,
                'buyer_messages' => 1,
                'seller_messages' => 1,
                'status' => MlBotOrder::STATUS_SENT,
                'qty' => 2,
            ]);
        }

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/messages/packs/2001/sellers/123?'));
        Http::assertSentCount($cachedSeller ? 2 : 3);
    }

    public function test_scan_limits_failed_conversation_checks_without_losing_orders(): void
    {
        $user = $this->createBotUser(true);
        $orders = [];
        for ($index = 0; $index < 61; $index++) {
            $orders[] = $this->order(1001 + $index, 2001 + $index);
        }

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/orders/search*' => fn (Request $request) => Http::response([
                'results' => array_slice($orders, (int) $request['offset'], 50),
            ]),
            'https://api.mercadolibre.com/messages/packs/*' => Http::response([
                'message' => 'Conversation unavailable',
            ], 404),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/scan', [
            'start_date' => '2026-10-23',
            'max_pages' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('summary.scanned_orders', 61)
            ->assertJsonPath('summary.eligible', 61)
            ->assertJsonPath('summary.conversation_checks', 60)
            ->assertJsonPath('counts.pending', 61)
            ->assertJsonCount(61, 'orders');

        // Cada tentativa falha consulta o carrinho e depois o pedido.
        Http::assertSentCount(122);
        $this->assertSame(61, MlBotOrder::where('user_id', $user->id)->count());
        $this->assertSame(0, MlBotOrder::where('user_id', $user->id)->whereNotNull('conversation_checked_at')->count());
    }

    private function createBotUser(bool $cachedSeller): User
    {
        config()->set('services.mercado_livre.base_url', 'https://api.mercadolibre.com');

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        MercadoLivreAccount::create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'seller_id' => $cachedSeller ? 123 : null,
        ]);

        $group = MlBotGroup::create([
            'user_id' => $user->id,
            'name' => 'Produtos personalizados',
            'template' => 'Envie os dados da personalizacao.',
        ]);
        MlBotItem::create([
            'user_id' => $user->id,
            'group_id' => $group->id,
            'item_id' => 'MLB123',
        ]);

        return $user;
    }

    private function order(int $orderId, int $packId): array
    {
        return [
            'id' => $orderId,
            'pack_id' => $packId,
            'status' => 'paid',
            'date_created' => '2026-10-24T12:00:00Z',
            'buyer' => ['id' => 456],
            'order_items' => [
                ['item' => ['id' => 'MLB123', 'title' => 'Produto personalizado'], 'quantity' => 1],
            ],
        ];
    }
}
