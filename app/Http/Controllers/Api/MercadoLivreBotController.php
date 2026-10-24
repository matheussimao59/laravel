<?php

namespace App\Http\Controllers\Api;

use App\Models\MlBotGroup;
use App\Models\MlBotOrder;
use App\Services\MercadoLivreService;
use App\Support\ExternalServiceException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

    /**
     * Confirmacao manual: envia a mensagem do grupo para o comprador
     * e grava no banco que o comando foi atendido.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'integer', 'min:1'],
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
        $template = trim((string) ($group?->template ?? ''));

        if ($template === '') {
            return response()->json([
                'error' => 'bot_group_without_message',
                'message' => 'Defina a mensagem do grupo antes de confirmar o envio.',
            ], 422);
        }

        try {
            $accessToken = $this->service->accessTokenForUser($user);
            $seller = $this->service->cachedSellerForUser($user);
            $sellerId = (int) ($seller['id'] ?? 0);

            if ($sellerId <= 0) {
                return response()->json([
                    'error' => 'ml_seller_unknown',
                    'message' => 'Conta Mercado Livre sem vendedor valido. Rode uma varredura no painel.',
                ], 422);
            }

            $response = $this->service->sendCustomization(
                $accessToken,
                $sellerId,
                (int) $first->order_id,
                $first->pack_id,
                $template
            );

            if (!($response['ok'] ?? false)) {
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
            ], $exception->status());
        }

        // Marca todos os pedidos do mesmo carrinho/compra como atendidos.
        if ($first->pack_id) {
            MlBotOrder::where('user_id', $user->id)
                ->where('pack_id', $first->pack_id)
                ->update([
                    'status' => MlBotOrder::STATUS_SENT,
                    'sent_message' => $template,
                    'sent_at' => now(),
                ]);
        } else {
            $first->update([
                'status' => MlBotOrder::STATUS_SENT,
                'sent_message' => $template,
                'sent_at' => now(),
            ]);
        }

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
