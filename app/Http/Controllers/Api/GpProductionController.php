<?php

namespace App\Http\Controllers\Api;

use App\Models\GpProductionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpProductionController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $productionOrders = GpProductionOrder::where('user_id', $user->id)
            ->with(['order.files'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['production_orders' => $productionOrders]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $productionOrder = GpProductionOrder::where('id', $id)->where('user_id', $user->id)->first();
        if (!$productionOrder) {
            return response()->json(['message' => 'Ordem de producao nao encontrada.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'stage' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'started_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $productionOrder->update($request->only(['stage', 'priority', 'notes', 'started_at']));

        return response()->json([
            'message' => 'Ordem de producao atualizada com sucesso.',
            'production_order' => $productionOrder,
        ]);
    }
}
