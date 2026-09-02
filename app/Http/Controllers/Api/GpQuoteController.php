<?php

namespace App\Http\Controllers\Api;

use App\Models\GpQuote;
use App\Models\GpQuoteItem;
use App\Models\GpOrder;
use App\Models\GpProductionOrder;
use App\Models\GpDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GpQuoteController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $quotes = GpQuote::where('user_id', $user->id)
            ->with(['items', 'client'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['quotes' => $quotes]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'client_id' => ['nullable', 'integer'],
            'client_name' => ['required', 'string', 'max:255'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'valid_until' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $quote = DB::transaction(function () use ($request, $user) {
            $quote = GpQuote::create([
                'user_id' => $user->id,
                'client_id' => $request->input('client_id'),
                'client_name' => trim($request->input('client_name')),
                'discount' => $request->input('discount', 0),
                'total' => $request->input('total'),
                'status' => $request->input('status', 'rascunho'),
                'notes' => $request->input('notes'),
                'valid_until' => $request->input('valid_until'),
                'delivery_date' => $request->input('delivery_date'),
            ]);

            foreach ($request->input('items') as $item) {
                GpQuoteItem::create([
                    'quote_id' => $quote->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                ]);
            }

            return $quote;
        });

        $quote->load(['items', 'client']);

        return response()->json(['message' => 'Orcamento criado com sucesso.', 'quote' => $quote], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $quote = GpQuote::where('id', $id)->where('user_id', $user->id)->first();
        if (!$quote) {
            return response()->json(['message' => 'Orcamento nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'client_name' => ['sometimes', 'string', 'max:255'],
            'discount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'total' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'delivery_date' => ['sometimes', 'nullable', 'date'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.product_name' => ['required_with:items', 'string', 'max:255'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.subtotal' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($quote, $request) {
            $quote->update($request->only([
                'client_id', 'client_name', 'discount', 'total',
                'status', 'notes', 'valid_until', 'delivery_date',
            ]));

            if ($request->has('items')) {
                $quote->items()->delete();
                foreach ($request->input('items') as $item) {
                    GpQuoteItem::create([
                        'quote_id' => $quote->id,
                        'product_id' => $item['product_id'] ?? null,
                        'product_name' => $item['product_name'],
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ]);
                }
            }
        });

        $quote->load(['items', 'client']);

        return response()->json(['message' => 'Orcamento atualizado com sucesso.', 'quote' => $quote]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $quote = GpQuote::where('id', $id)->where('user_id', $user->id)->first();
        if (!$quote) {
            return response()->json(['message' => 'Orcamento nao encontrado.'], 404);
        }

        DB::transaction(function () use ($quote) {
            $quote->items()->delete();
            $quote->delete();
        });

        return response()->json(['message' => 'Orcamento excluido com sucesso.'], 204);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $quote = GpQuote::where('id', $id)->where('user_id', $user->id)->with('items')->first();
        if (!$quote) {
            return response()->json(['message' => 'Orcamento nao encontrado.'], 404);
        }

        if ($quote->status === 'aprovado') {
            return response()->json(['message' => 'Orcamento ja foi aprovado.'], 422);
        }

        $order = DB::transaction(function () use ($quote, $user) {
            $quote->update(['status' => 'aprovado']);

            $firstItem = $quote->items->first();

            $order = GpOrder::create([
                'user_id' => $user->id,
                'quote_id' => $quote->id,
                'client_id' => $quote->client_id,
                'client_name' => $quote->client_name,
                'product_name' => $firstItem ? $firstItem->product_name : 'Orcamento',
                'description' => 'Pedido gerado a partir do orcamento #' . $quote->id,
                'qty' => $quote->items->sum('qty'),
                'unit_price' => 0,
                'total' => $quote->total,
                'status' => 'recebido',
                'payment_status' => 'pendente',
            ]);

            GpProductionOrder::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'client_name' => $order->client_name,
                'product_name' => $order->product_name,
                'qty' => $order->qty,
                'total' => $order->total,
                'stage' => 'fila',
                'priority' => 'normal',
                'deadline' => $order->deadline,
            ]);

            GpDelivery::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'client_name' => $order->client_name,
                'product_name' => $order->product_name,
                'status' => 'pendente',
            ]);

            return $order;
        });

        $order->load(['files', 'events']);

        return response()->json([
            'message' => 'Orcamento aprovado e pedido criado com sucesso.',
            'order' => $order,
        ], 201);
    }
}
