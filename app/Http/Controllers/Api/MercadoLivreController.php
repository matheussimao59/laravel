<?php

namespace App\Http\Controllers\Api;

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
            return response()->json($this->service->exchangeToken(
                (string) $request->input('code'),
                (string) $request->input('redirect_uri'),
                $request->filled('code_verifier') ? (string) $request->input('code_verifier') : null,
            ));
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
            'access_token' => ['required', 'string'],
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
            $fromDate = trim((string) $request->input('from_date'));
            if ($fromDate === '') {
                $fromDate = now()->subDays(30)->toIso8601String();
            }

            return response()->json($this->service->syncOrders(
                (string) $request->input('access_token'),
                $fromDate,
                $request->filled('to_date') ? (string) $request->input('to_date') : null,
                $request->boolean('include_payments_details'),
                $request->boolean('include_shipments_details'),
                (int) $request->input('max_pages', 120),
            ));
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
            'access_token' => ['required', 'string'],
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
            return response()->json($this->service->sendCustomization(
                (string) $request->input('access_token'),
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
}
