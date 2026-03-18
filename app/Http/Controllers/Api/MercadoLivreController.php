<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\MercadoLivreService;
use App\Support\ExternalServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class MercadoLivreController
{
    public function __construct(private readonly MercadoLivreService $service)
    {
    }

    public function oauthToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
            'redirect_uri' => ['required', 'string'],
            'code_verifier' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Dados invalidos para trocar codigo OAuth.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            /** @var User|null $user */
            $user = $request->user();
            $payload = $this->service->exchangeToken(
                (string) $request->input('code'),
                (string) $request->input('redirect_uri'),
                $request->filled('code_verifier') ? (string) $request->input('code_verifier') : null,
            );

            if ($user) {
                $account = $this->service->saveOAuthAccount($user, $payload);

                return response()->json([
                    'message' => 'Conta Mercado Livre conectada com sucesso.',
                    'account' => $account,
                ]);
            }

            return response()->json($payload);
        } catch (ExternalServiceException $exception) {
            return response()->json([
                'error' => 'ml_token_exchange_failed',
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->status());
        }
    }

    public function sync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_token' => ['nullable', 'string'],
            'from_date' => ['nullable', 'string'],
            'to_date' => ['nullable', 'string'],
            'include_payments_details' => ['nullable', 'boolean'],
            'include_shipments_details' => ['nullable', 'boolean'],
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Dados invalidos para sincronizacao Mercado Livre.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            /** @var User|null $user */
            $user = $request->user();
            $fromDate = trim((string) $request->input('from_date'));
            if ($fromDate === '') {
                $fromDate = now()->subDays(30)->toIso8601String();
            }

            $accessToken = trim((string) $request->input('access_token'));
            if ($accessToken === '' && $user) {
                $accessToken = $this->service->accessTokenForUser($user);
            }

            $payload = $this->service->syncOrders(
                $accessToken,
                $fromDate,
                $request->filled('to_date') ? (string) $request->input('to_date') : null,
                $request->boolean('include_payments_details'),
                $request->boolean('include_shipments_details'),
                (int) $request->input('max_pages', 120),
            );

            if ($user && is_array($payload['seller'] ?? null)) {
                $this->service->rememberSellerForUser($user, $payload['seller']);
            }

            return response()->json($payload);
        } catch (ExternalServiceException $exception) {
            return response()->json([
                'error' => 'sync_failed',
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->status());
        }
    }

    public function sendCustomization(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_token' => ['nullable', 'string'],
            'seller_id' => ['required', 'integer', 'min:1'],
            'order_id' => ['required', 'integer', 'min:1'],
            'pack_id' => ['nullable', 'integer', 'min:1'],
            'message' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_payload',
                'message' => 'Campos obrigatorios ausentes para envio da mensagem.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            /** @var User|null $user */
            $user = $request->user();
            $accessToken = trim((string) $request->input('access_token'));
            if ($accessToken === '' && $user) {
                $accessToken = $this->service->accessTokenForUser($user);
            }

            return response()->json($this->service->sendCustomization(
                $accessToken,
                (int) $request->input('seller_id'),
                (int) $request->input('order_id'),
                $request->filled('pack_id') ? (int) $request->input('pack_id') : null,
                (string) $request->input('message'),
            ));
        } catch (ExternalServiceException $exception) {
            return response()->json([
                'ok' => false,
                'error' => 'internal_error',
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->status());
        }
    }

    public function account(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'account' => $this->service->accountStatusForUser($user),
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->service->disconnectAccountForUser($user);

        return response()->json([
            'message' => 'Conta Mercado Livre desconectada com sucesso.',
        ]);
    }
}
