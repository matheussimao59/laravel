<?php

namespace Tests\Feature;

use App\Models\MercadoLivreAccount;
use App\Models\MlBotGroup;
use App\Models\MlBotOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MercadoLivreBotConfirmTest extends TestCase
{
    use RefreshDatabase;

    public static function packIds(): array
    {
        return ['with pack' => [2001], 'without pack' => [null]];
    }

    #[DataProvider('packIds')]
    public function test_confirm_sends_custom_text_using_other_and_does_not_resend(?int $packId): void
    {
        $row = $this->createPendingOrder($packId);
        $targetId = $packId ?? $row->order_id;

        Http::preventStrayRequests();
        Http::fake([
            "https://api.mercadolibre.com/messages/packs/{$targetId}/sellers/123*" => Http::response(['messages' => []]),
            "https://api.mercadolibre.com/messages/action_guide/packs/{$targetId}?*" => Http::response([
                'options' => [['id' => 'OTHER', 'cap_available' => 1]],
            ]),
            "https://api.mercadolibre.com/messages/action_guide/packs/{$targetId}/option?*" => Http::response([
                'id' => 'message-1', 'status' => 'available',
            ]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertOk()
            ->assertJsonPath('counts.sent', 1);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === "https://api.mercadolibre.com/messages/action_guide/packs/{$targetId}/option?tag=post_sale"
            && $request->data() === ['option_id' => 'OTHER', 'text' => $row->group->template]);
        $this->assertSame(MlBotOrder::STATUS_SENT, $row->fresh()->status);
        $this->assertSame($row->group->template, $row->fresh()->sent_message);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertOk()
            ->assertJsonPath('message', 'Mensagem ja enviada para esta compra.');
        Http::assertSentCount(3);
    }

    public function test_confirm_replies_to_the_agent_in_an_existing_conversation(): void
    {
        $row = $this->createPendingOrder(2001);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['messages' => [[
                        'from' => ['user_id' => 3037675074],
                        'to' => ['user_id' => 123],
                        'text' => 'Quero personalizar o produto.',
                        'message_date' => ['created' => '2026-10-24T12:00:00Z'],
                    ]]]);
                }

                return Http::response(['messages' => [['id' => 'message-1', 'status' => 'available']]]);
            },
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.mercadolibre.com/messages/packs/2001/sellers/123?tag=post_sale'
            && $request->data() === [
                'from' => ['user_id' => 123],
                'to' => ['user_id' => 3037675074],
                'text' => $row->group->template,
            ]);
        Http::assertSentCount(2);
        $this->assertSame(MlBotOrder::STATUS_SENT, $row->fresh()->status);
    }

    public function test_confirm_reports_the_upstream_refusal_without_marking_as_sent(): void
    {
        $row = $this->createPendingOrder(2001);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => []]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response([]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => Http::response([
                'message' => 'Voce nao tem permissao para executar a opcao OTHER novamente.',
                'error' => 'bad_request',
            ], 403),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(422)
            ->assertJsonPath('details.upstream_status', 403)
            ->assertJsonPath('message', 'O Mercado Livre recusou o envio (HTTP 403): Voce nao tem permissao para executar a opcao OTHER novamente.');

        Http::assertSentCount(3);
        $this->assertSame(MlBotOrder::STATUS_PENDING, $row->fresh()->status);
        $this->assertNull($row->fresh()->sent_at);
    }

    public function test_confirm_uses_direct_messages_for_an_explicit_excepted_case(): void
    {
        $row = $this->createPendingOrder(2001);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['messages' => []])
                : Http::response(['id' => 'message-1', 'status' => 'available']),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response([
                'cause' => 'blocked_by_excepted_case',
                'message' => 'Use o recurso de mensagens.',
            ], 400),
            'https://api.mercadolibre.com/orders/1001' => Http::response(['buyer' => ['id' => 456]]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['to']['user_id'] === 456
            && $request['text'] === $row->group->template);
        Http::assertSentCount(4);
    }

    public function test_confirm_does_not_mark_a_moderated_message_as_sent(): void
    {
        $row = $this->createPendingOrder(2001);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => []]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response([]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => Http::response([
                'status' => 'moderated',
                'message_moderation' => ['status' => 'rejected', 'reason' => 'AUTOMATIC_MESSAGE'],
            ]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ml_message_moderated');
        $this->assertSame(MlBotOrder::STATUS_PENDING, $row->fresh()->status);
    }

    public function test_confirm_handles_connection_failure_without_resending(): void
    {
        $row = $this->createPendingOrder(2001);
        $postAttempts = 0;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => []]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response([]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => function () use (&$postAttempts) {
                $postAttempts++;
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(502)
            ->assertJsonPath('error', 'ml_message_connection_failed');
        $this->assertSame(1, $postAttempts);
        $this->assertSame(MlBotOrder::STATUS_PENDING, $row->fresh()->status);
        $this->assertNull($row->fresh()->sent_at);
    }

    public function test_confirm_rejects_text_over_the_message_limit_before_calling_ml(): void
    {
        $row = $this->createPendingOrder(2001);
        $row->group->update(['template' => str_repeat('a', 351)]);
        Http::preventStrayRequests();
        Http::fake();

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ml_message_too_long');
        Http::assertNothingSent();
        $this->assertSame(MlBotOrder::STATUS_PENDING, $row->fresh()->status);
    }

    private function createPendingOrder(?int $packId): MlBotOrder
    {
        config()->set('services.mercado_livre.base_url', 'https://api.mercadolibre.com');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($user);

        MercadoLivreAccount::create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'seller_id' => 123,
        ]);
        $group = MlBotGroup::create([
            'user_id' => $user->id,
            'name' => 'Personalizados',
            'template' => 'Envie os dados da personalizacao.',
        ]);

        return MlBotOrder::create([
            'user_id' => $user->id,
            'group_id' => $group->id,
            'order_id' => 1001,
            'pack_id' => $packId,
            'buyer_id' => 456,
            'status' => MlBotOrder::STATUS_PENDING,
        ]);
    }
}
