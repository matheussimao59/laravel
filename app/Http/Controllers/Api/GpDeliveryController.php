<?php

namespace App\Http\Controllers\Api;

use App\Models\GpDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpDeliveryController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $deliveries = GpDelivery::where('user_id', $user->id)
            ->with(['order'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['deliveries' => $deliveries]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $delivery = GpDelivery::where('id', $id)->where('user_id', $user->id)->first();
        if (!$delivery) {
            return response()->json(['message' => 'Entrega nao encontrada.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'string'],
            'method' => ['sometimes', 'nullable', 'string'],
            'scheduled_date' => ['sometimes', 'nullable', 'date'],
            'delivered_at' => ['sometimes', 'nullable', 'date'],
            'address' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $delivery->update($request->only([
            'status', 'method', 'scheduled_date', 'delivered_at', 'address', 'notes',
        ]));

        return response()->json(['message' => 'Entrega atualizada com sucesso.', 'delivery' => $delivery]);
    }
}
