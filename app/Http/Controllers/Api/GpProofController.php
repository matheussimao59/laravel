<?php

namespace App\Http\Controllers\Api;

use App\Models\GpOrder;
use App\Models\GpOrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpProofController
{
    public function show(Request $request, string $orderId, string $token): JsonResponse
    {
        $order = GpOrder::where('id', $orderId)
            ->where('proof_token', $token)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Prova digital nao encontrada.'], 404);
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'client_name' => $order->client_name,
                'product_name' => $order->product_name,
                'qty' => $order->qty,
                'art_status' => $order->art_status,
            ],
        ]);
    }

    public function respond(Request $request, string $orderId, string $token): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'approved' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $order = GpOrder::where('id', $orderId)
            ->where('proof_token', $token)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Prova digital nao encontrada.'], 404);
        }

        $approved = (bool) $request->input('approved');

        if ($approved) {
            $order->update(['art_status' => 'arte_aprovada']);
            $note = 'Cliente aprovou a prova digital.';
        } else {
            $order->update(['art_status' => 'em_revisao']);
            $note = 'Cliente solicitou ajustes na prova digital.';
        }

        GpOrderEvent::create([
            'order_id' => $order->id,
            'status' => $order->status,
            'note' => $note,
            'created_by' => 'Cliente (link da prova)',
        ]);

        return response()->json([
            'message' => $approved ? 'Prova aprovada! Obrigado.' : 'Ajustes solicitados. Entraremos em contato.',
            'art_status' => $order->art_status,
        ]);
    }
}