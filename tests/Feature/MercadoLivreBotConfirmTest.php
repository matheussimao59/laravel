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
            "https://api.mercadolibre.com/messages/action_guide/packs/{$targetId}/caps_available?*" => Http::response(['cap_available' => 1]),
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
        Http::assertSentCount(4);
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
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response(['options' => [['id' => 'OTHER']]]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/caps_available?*' => Http::response(['cap_available' => 1]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => Http::response([
                'message' => 'Voce nao tem permissao para executar a opcao OTHER novamente.',
                'error' => 'bad_request',
            ], 403),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(422)
            ->assertJsonPath('details.upstream_status', 403)
            ->assertJsonPath('message', 'O Mercado Livre recusou o envio (HTTP 403): Voce nao tem permissao para executar a opcao OTHER novamente.');

        Http::assertSentCount(4);
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
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response(['options' => [['id' => 'OTHER']]]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/caps_available?*' => Http::response(['cap_available' => 1]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => Http::response([
                'status' => 'moderated',
                'message_moderation' => ['status' => 'rejected', 'reason' => 'AUTOMATIC_MESSAGE'],
            ]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ml_message_moderated');
        $this->assertSame(MlBotOrder::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame('blocked', $row->fresh()->send_status);

        $this->postJson('/api/integrations/mercado-livre/bot/skip', ['order_id' => $row->order_id, 'skipped' => true])->assertOk();
        $this->postJson('/api/integrations/mercado-livre/bot/skip', ['order_id' => $row->order_id, 'skipped' => false])->assertOk();
        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(409)
            ->assertJsonPath('error', 'ml_message_send_guarded');
        Http::assertSentCount(4);
    }

    public function test_confirm_handles_connection_failure_without_resending(): void
    {
        $row = $this->createPendingOrder(2001);
        $postAttempts = 0;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => []]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response(['options' => [['id' => 'OTHER']]]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/caps_available?*' => Http::response(['cap_available' => 1]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => function () use (&$postAttempts) {
                $postAttempts++;
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => $row->order_id])
            ->assertStatus(502)
            ->assertJsonPath('error', 'ml_message_connection_failed');
        $this->assertSame(1, $postAttempts);
        $this->assertSame('uncertain', $row->fresh()->send_status);
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

    public function test_preview_only_reads_permissions_and_does_not_send(): void
    {
        $row = $this->createPendingOrder(2001);
        $this->fakeAvailableOptions();

        $this->getJson('/api/integrations/mercado-livre/bot/orders/1001/message-options')
            ->assertOk()
            ->assertJsonPath('mode', 'action_guide')
            ->assertJsonPath('options.0.id', 'OTHER')
            ->assertJsonPath('options.0.enabled', true)
            ->assertJsonPath('options.0.cap_available', 1);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertNull($row->fresh()->send_status);
        $this->assertNull($row->fresh()->sent_at);
    }

    public function test_confirm_sends_the_reviewed_text_without_changing_the_group(): void
    {
        $row = $this->createPendingOrder(2001);
        $original = $row->group->template;
        $this->fakeAvailableOptions();

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', [
            'order_id' => 1001, 'option_id' => 'OTHER', 'message' => 'Texto revisado para esta compra.',
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['option_id'] === 'OTHER' && $request['text'] === 'Texto revisado para esta compra.');
        $this->assertSame('Texto revisado para esta compra.', $row->fresh()->sent_message);
        $this->assertSame($original, $row->group->fresh()->template);
    }

    public function test_confirm_rechecks_capacity_after_preview(): void
    {
        $row = $this->createPendingOrder(2001);
        $cap = 1;
        $this->fakeAvailableOptions(['id' => 'OTHER'], function () use (&$cap) {
            return Http::response(['cap_available' => $cap]);
        });

        $this->getJson('/api/integrations/mercado-livre/bot/orders/1001/message-options')->assertOk();
        $cap = 0;
        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => 1001, 'option_id' => 'OTHER'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ml_message_not_allowed');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertNull($row->fresh()->send_status);
    }

    public function test_confirm_uses_only_the_official_template_returned_by_ml(): void
    {
        $row = $this->createPendingOrder(2001);
        $officialText = 'Modelo oficial retornado pelo Mercado Livre.';
        $this->fakeAvailableOptions([
            'id' => 'REQUEST_VARIANTS',
            'templates' => [['id' => 'TEMPLATE_VARIANTS_1', 'text' => $officialText]],
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', [
            'order_id' => 1001, 'option_id' => 'REQUEST_VARIANTS', 'template_id' => 'TEMPLATE_VARIANTS_1',
            'message' => 'Nao substituir o texto oficial por este texto.',
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->data() === ['option_id' => 'REQUEST_VARIANTS', 'template_id' => 'TEMPLATE_VARIANTS_1']);
        $this->assertSame($officialText, $row->fresh()->sent_message);
    }

    public function test_unavailable_option_is_not_replaced_by_other(): void
    {
        $this->createPendingOrder(2001);
        $this->fakeAvailableOptions();

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', [
            'order_id' => 1001, 'option_id' => 'REQUEST_VARIANTS', 'template_id' => 'MADE_UP_TEMPLATE',
        ])->assertStatus(422)->assertJsonPath('error', 'ml_message_option_unavailable');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_moderated_history_blocks_new_messages_and_is_not_counted_as_sent(): void
    {
        $row = $this->createPendingOrder(2001);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => [[
                'from' => ['user_id' => 123], 'status' => 'moderated',
                'message_moderation' => ['status' => 'rejected', 'reason' => 'AUTOMATIC_MESSAGE'],
            ]]]),
        ]);

        $this->getJson('/api/integrations/mercado-livre/bot/orders/1001/message-options')
            ->assertOk()->assertJsonPath('mode', 'blocked')->assertJsonCount(0, 'options');
        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => 1001])
            ->assertStatus(422)->assertJsonPath('error', 'ml_message_not_allowed');
        $this->assertSame('blocked', $row->fresh()->send_status);
        $this->assertSame(MlBotOrder::STATUS_PENDING, $row->fresh()->status);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_another_order_in_the_same_pack_prevents_a_second_send(): void
    {
        $row = $this->createPendingOrder(2001);
        MlBotOrder::create([
            'user_id' => $row->user_id, 'group_id' => $row->group_id, 'order_id' => 1002,
            'pack_id' => 2001, 'status' => MlBotOrder::STATUS_PENDING,
            'send_status' => 'sending', 'send_reason' => 'Envio ja iniciado.',
        ]);
        Http::fake();

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => 1001])
            ->assertStatus(409)->assertJsonPath('error', 'ml_message_send_guarded');
        Http::assertNothingSent();
    }

    public function test_message_options_cannot_read_another_users_order(): void
    {
        $this->createPendingOrder(2001);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        Http::fake();

        $this->getJson('/api/integrations/mercado-livre/bot/orders/1001/message-options')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_rate_limited_message_post_is_not_retried(): void
    {
        $row = $this->createPendingOrder(2001);
        $attempts = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => []]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response(['options' => [['id' => 'OTHER']]]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/caps_available?*' => Http::response(['cap_available' => 1]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => function () use (&$attempts) {
                $attempts++;
                return Http::response(['message' => 'Limite de chamadas excedido.'], 429);
            },
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => 1001])->assertStatus(429);
        $this->assertSame(1, $attempts);
        $this->assertSame('blocked', $row->fresh()->send_status);
    }

    public function test_pending_moderation_remains_uncertain_instead_of_sent(): void
    {
        $row = $this->createPendingOrder(2001);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => []]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response(['options' => [['id' => 'OTHER']]]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/caps_available?*' => Http::response(['cap_available' => 1]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => Http::response([
                'status' => 'pending', 'message_moderation' => ['status' => 'pending'],
            ]),
        ]);

        $this->postJson('/api/integrations/mercado-livre/bot/confirm', ['order_id' => 1001])
            ->assertStatus(409)->assertJsonPath('error', 'ml_message_delivery_uncertain');
        $this->assertSame(MlBotOrder::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame('uncertain', $row->fresh()->send_status);
    }

    private function fakeAvailableOptions(array $option = ['id' => 'OTHER'], ?\Closure $caps = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/messages/packs/2001/sellers/123*' => Http::response(['messages' => []]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001?*' => Http::response(['options' => [$option]]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/caps_available?*' => $caps ?? Http::response(['cap_available' => 1]),
            'https://api.mercadolibre.com/messages/action_guide/packs/2001/option?*' => Http::response(['id' => 'message-1', 'status' => 'available']),
        ]);
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
