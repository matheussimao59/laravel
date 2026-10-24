<?php

namespace App\Http\Controllers\Api;

use App\Models\MlBotGroup;
use App\Models\MlBotOrder;
use App\Services\MercadoLivreService;
use App\Support\ExternalServiceException;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

final class MercadoLivreBotController
{
    public function __construct(private readonly MercadoLivreService $service)
    {
    }

    /* ------------------------------------------------------------
     * Config: data inicial + grupos com mensagem
     * ----------------------------------------------------------- */

    public function config(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        return response()->json($this->service->botConfigForUser($user));
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Informe uma data inicial valida.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        $saved = $this->service->saveBotConfig($user, (string) $request->input('start_date'));

        return response()->json([
            'message' => 'Configuracao do bot salva.',
            'settings' => $saved,
        ]);
    }

    /* ------------------------------------------------------------
     * Grupos de anuncios (cada grupo com a propria mensagem)
     * ----------------------------------------------------------- */

    public function storeGroup(Request $request): JsonResponse
    {
        $validation = $this->validateGroupPayload($request);
        if ($validation !== null) {
            return $validation;
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        $group = $this->service->storeBotGroup(
            $user,
            trim((string) $request->input('name')),
            trim((string) $request->input('template'))
        );

        return response()->json([
            'message' => 'Grupo criado.',
            'group' => $this->service->mapBotGroup($group),
        ]);
    }

    public function updateGroup(Request $request, int $groupId): JsonResponse
    {
        $validation = $this->validateGroupPayload($request);
        if ($validation !== null) {
            return $validation;
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        $group = $this->service->updateBotGroup(
            $user,
            $groupId,
            trim((string) $request->input('name')),
            trim((string) $request->input('template'))
        );

        if ($group === null) {
            return response()->json([
                'error' => 'bot_group_not_found',
                'message' => 'Grupo nao encontrado para esta conta.',
            ], 404);
        }

        return response()->json([
            'message' => 'Grupo atualizado.',
            'group' => $this->service->mapBotGroup($group),
        ]);
    }

    public function destroyGroup(Request $request, int $groupId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        if (!$this->service->destroyBotGroup($user, $groupId)) {
            return response()->json([
                'error' => 'bot_group_not_found',
                'message' => 'Grupo nao encontrado para esta conta.',
            ], 404);
        }

        return response()->json(['message' => 'Grupo removido.']);
    }

    /**
     * Substitui os anuncios de um grupo (alternancia validada no
     * upload de compra pedido um item por grupo por vez).
     */
    public function saveItems(Request $request, int $groupId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array'],
            'items.*.item_id' => ['required', 'string', 'max:100'],
            'items.*.title' => ['nullable', 'string'],
            'items.*.thumbnail' => ['nullable', 'string'],
            'items.*.seller_sku' => ['nullable', 'string', 'max:120'],
            'items.*.price' => ['nullable', 'numeric'],
            'items.*.status' => ['nullable', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Lista de anuncios invalida.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        $group = $this->service->replaceBotGroupItems(
            $user,
            $groupId,
            (array) $request->input('items')
        );

        if ($group === null) {
            return response()->json([
                'error' => 'bot_group_not_found',
                'message' => 'Grupo nao encontrado para esta conta.',
            ], 404);
        }

        return response()->json([
            'message' => 'Anuncios do grupo salvos.',
            'group' => $this->service->mapBotGroup($group),
        ]);
    }

    /* ------------------------------------------------------------
     * Varredura + comandos de pedido
     * ----------------------------------------------------------- */

    public function scan(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => ['nullable', 'date'],
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Parametros da varredura invalidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        try {
            $summary = $this->service->scanBotOrders(
                $user,
                trim((string) $request->input('start_date')) ?: null,
                (int) $request->input('max_pages', 10)
            );

            return response()->json([
                'message' => 'Varredura do bot concluida.',
                'orders' => $this->service->botOrdersForUser($user),
                'counts' => $summary['counts'],
                'summary' => $summary,
            ]);
        } catch (ExternalServiceException $exception) {
            return response()->json([
                'error' => 'ml_bot_scan_failed',
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->status());
        }
    }

    public function orders(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        return response()->json([
            'orders' => $this->service->botOrdersForUser($user),
            'counts' => $this->service->botOrderCounts($user),
        ]);
    }

    public function messageOptions(Request $request, int $orderId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Sessao invalida.'], 401);
        }
        $row = MlBotOrder::where('user_id', $user->id)->where('order_id', $orderId)->first();
        if (!$row) {
            return response()->json(['message' => 'Pedido nao catalogado para esta conta.'], 404);
        }
        $guarded = $this->purchaseOrders($user, $row)->get()->first(fn (MlBotOrder $item) => $this->sendGuardReason($item) !== null);
        if ($guarded) {
            return response()->json(['mode' => 'blocked', 'options' => [], 'block_reason' => $this->sendGuardReason($guarded)]);
        }

        try {
            $accessToken = $this->service->accessTokenForUser($user);
            $seller = $this->service->sellerForUser($user, $accessToken);
            $context = $this->service->messageOptions($accessToken, (int) $seller['id'], (int) $row->order_id, $row->pack_id);
            unset($context['recipient_id']);

            return response()->json($context);
        } catch (ExternalServiceException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'details' => $exception->details()], $exception->status() === 401 ? 422 : $exception->status());
        } catch (ConnectionException) {
            return response()->json(['message' => 'Falha ao consultar as permissoes do Mercado Livre. Nenhuma mensagem foi enviada.'], 502);
        }
    }

    /**
     * Confirmacao manual: envia a mensagem revisada ou o modelo oficial
     * e grava no banco que o comando foi atendido.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'integer', 'min:1'],
            'option_id' => ['sometimes', 'required', 'in:OTHER,REQUEST_VARIANTS'],
            'template_id' => ['nullable', 'string', 'max:150'],
            'message' => ['sometimes', 'required', 'string', 'max:350'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Informe o pedido que deve receber a mensagem.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        $rows = MlBotOrder::where('user_id', $user->id)
            ->where('order_id', (int) $request->input('order_id'))
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'error' => 'bot_order_not_found',
                'message' => 'Rode a varredura: este pedido ainda nao foi catalogado pelo bot.',
            ], 404);
        }

        $first = $rows->first();
        if ($first->status === MlBotOrder::STATUS_SENT) {
            return response()->json([
                'message' => 'Mensagem ja enviada para esta compra.',
                'orders' => $this->service->botOrdersForUser($user),
                'counts' => $this->service->botOrderCounts($user),
            ]);
        }

        $group = $rows->pluck('group_id')->filter()->first() !== null
            ? MlBotGroup::find($rows->pluck('group_id')->filter()->first())
            : null;
        $template = trim((string) $request->input('message', $group?->template ?? ''));
        $optionId = (string) $request->input('option_id', 'OTHER');

        if ($template === '' && $optionId === 'OTHER') {
            return response()->json([
                'error' => 'bot_group_without_message',
                'message' => 'Defina a mensagem do grupo antes de confirmar o envio.',
            ], 422);
        }

        try {
            $accessToken = $this->service->accessTokenForUser($user);
            $seller = $this->service->sellerForUser($user, $accessToken);
            $sellerId = (int) ($seller['id'] ?? 0);

            // Reserva por compra/carrinho no banco, inclusive entre duas abas.
            // Um envio interrompido nao e liberado automaticamente para repetir.
            $guardReason = DB::transaction(function () use ($user, $first) {
                $purchase = $this->purchaseOrders($user, $first)->orderBy('id')->lockForUpdate()->get();
                foreach ($purchase as $row) {
                    if ($reason = $this->sendGuardReason($row)) {
                        return $reason;
                    }
                }
                $this->purchaseOrders($user, $first)->update([
                    'send_status' => 'sending',
                    'send_reason' => 'Envio em andamento ou sem conclusao confirmada. Confira o chat antes de qualquer nova tentativa.',
                ]);

                return null;
            });
            if ($guardReason !== null) {
                return response()->json([
                    'error' => 'ml_message_send_guarded',
                    'message' => $guardReason,
                ], 409);
            }

            $response = $this->service->sendCustomization(
                $accessToken,
                $sellerId,
                (int) $first->order_id,
                $first->pack_id,
                $template,
                $optionId,
                $request->input('template_id')
            );

            if (!($response['ok'] ?? false)) {
                $stopped = ($response['attempted'] ?? false) || ($response['stop'] ?? false);
                $this->purchaseOrders($user, $first)->update([
                    'send_status' => $stopped ? ((($response['status'] ?? 502) >= 500 || ($response['uncertain'] ?? false)) ? 'uncertain' : 'blocked') : null,
                    'send_reason' => $stopped ? ($response['message'] ?? 'Envio interrompido. Confira o chat no Mercado Livre.') : null,
                ]);
                return response()->json([
                    'error' => $response['error'] ?? 'ml_message_send_failed',
                    'message' => $response['message'] ?? 'Nao foi possivel enviar a mensagem pelo chat do Mercado Livre.',
                    'details' => $response['details'] ?? $response['message'] ?? null,
                ], $response['status'] ?? 502);
            }
        } catch (ExternalServiceException $exception) {
            return response()->json([
                'error' => 'ml_message_send_failed',
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->status() === 401 ? 422 : $exception->status());
        } catch (ConnectionException) {
            return response()->json(['message' => 'Falha ao conectar ao Mercado Livre. Confira o chat antes de tentar novamente.'], 502);
        }

        // Marca todos os pedidos do mesmo carrinho/compra como atendidos.
        $this->purchaseOrders($user, $first)->update([
            'status' => MlBotOrder::STATUS_SENT,
            'sent_message' => $response['sent_text'] ?? $template,
            'sent_at' => now(),
            'send_status' => 'sent',
            'send_reason' => null,
        ]);

        return response()->json([
            'message' => ($first->pack_id ? "Carrinho #{$first->pack_id}" : "Pedido #{$first->order_id}") . ' respondido pelo bot.',
            'orders' => $this->service->botOrdersForUser($user),
            'counts' => $this->service->botOrderCounts($user),
        ]);
    }

    public function skip(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'integer', 'min:1'],
            'skipped' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Informe o pedido e a marcacao (ignorar ou nao).',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Sessao invalida.'], 401);
        }

        $rows = MlBotOrder::where('user_id', $user->id)
            ->where('order_id', (int) $request->input('order_id'))
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'error' => 'bot_order_not_found',
                'message' => 'Pedido nao catalogado pelo bot. Rode a varredura.',
            ], 404);
        }

        $nextStatus = $request->boolean('skipped')
            ? MlBotOrder::STATUS_SKIPPED
            : MlBotOrder::STATUS_PENDING;

        foreach ($rows as $row) {
            $row->status = $nextStatus;
            $row->save();
        }

        return response()->json([
            'message' => $nextStatus === MlBotOrder::STATUS_SKIPPED
                ? 'Pedido marcado como "nao enviar".'
                : 'Pedido voltou para a fila pendente.',
            'orders' => $this->service->botOrdersForUser($user),
            'counts' => $this->service->botOrderCounts($user),
        ]);
    }

    private function purchaseOrders(User $user, MlBotOrder $row): Builder
    {
        $query = MlBotOrder::where('user_id', $user->id);

        return $row->pack_id ? $query->where('pack_id', $row->pack_id) : $query->where('order_id', $row->order_id);
    }

    private function sendGuardReason(MlBotOrder $row): ?string
    {
        if ($row->status === MlBotOrder::STATUS_SENT) {
            return 'Mensagem ja enviada para esta compra.';
        }
        if ($row->status === MlBotOrder::STATUS_SKIPPED) {
            return 'Compra marcada para nao enviar.';
        }

        return $row->send_status !== null
            ? ($row->send_reason ?: 'Envio ja iniciado ou bloqueado. Confira a compra no Mercado Livre.')
            : null;
    }

    private function validateGroupPayload(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'template' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Informe um nome para o grupo (mensagem opcional).',
                'errors' => $validator->errors(),
            ], 422);
        }

        return null;
    }
}
