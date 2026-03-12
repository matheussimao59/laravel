<?php

namespace App\Http\Controllers\Api;

use App\Services\FiscalProviderService;
use App\Support\ExternalServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class FiscalProviderController
{
    public function __construct(private readonly FiscalProviderService $service)
    {
    }

    public function emit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'integer', 'min:1'],
            'order_total' => ['nullable', 'numeric', 'min:0'],
            'invoice_series' => ['nullable', 'string', 'max:20'],
            'environment' => ['nullable', 'in:homologacao,producao'],
            'provider_name' => ['nullable', 'string', 'max:60'],
            'provider_base_url' => ['nullable', 'string', 'max:255'],
            'order_title' => ['nullable', 'string', 'max:255'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'emitter' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Dados invalidos para emissao de NF.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json($this->service->emit($request->all()));
        } catch (ExternalServiceException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->status());
        }
    }

    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider_ref' => ['required', 'string'],
            'provider_base_url' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Dados invalidos para consulta de NF.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json($this->service->status(
                (string) $request->input('provider_ref'),
                $request->filled('provider_base_url') ? (string) $request->input('provider_base_url') : null,
            ));
        } catch (ExternalServiceException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->status());
        }
    }
}
