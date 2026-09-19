<?php

namespace App\Http\Controllers\Api;

use App\Models\GpOrder;
use App\Models\GpOrderEvent;
use App\Models\GpOrderFile;
use App\Models\GpOrderItem;
use App\Models\GpProductionOrder;
use App\Models\GpDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class GpOrderController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $orders = GpOrder::where('user_id', $user->id)
            ->with(['files', 'events', 'items'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'client_name' => ['required', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:50'],
            'product_name' => ['required_without:items', 'string', 'max:255'],
            'product_size' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'qty' => ['required_without:items', 'integer', 'min:1'],
            'sticker_qty' => ['nullable', 'integer', 'min:0'],
            'art_status' => ['nullable', 'string', 'max:30'],
            'unit_price' => ['required_without:items', 'numeric', 'min:0'],
            'total' => ['required_without:items', 'numeric', 'min:0'],
            'status' => ['nullable', 'string'],
            'payment_status' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'payment_note' => ['nullable', 'string'],
            'delivery_method' => ['nullable', 'string'],
            'delivery_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'responsible' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $items = $this->parseItems($request->input('items'));
        $itemErrors = $this->validateItems($items);
        if (!empty($itemErrors)) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $itemErrors], 422);
        }

        $order = DB::transaction(function () use ($request, $user, $items) {
            $isMulti = count($items) > 0;
            $aggregate = $isMulti ? $this->aggregatesFromItems($items) : null;

            $order = GpOrder::create([
                'user_id' => $user->id,
                'quote_id' => $request->input('quote_id'),
                'client_id' => $request->input('client_id'),
                'client_name' => trim($request->input('client_name')),
                'client_phone' => $request->input('client_phone'),
                'product_name' => $aggregate ? $aggregate['product_name'] : trim($request->input('product_name')),
                'product_size' => $aggregate ? $aggregate['product_size'] : $request->input('product_size'),
                'description' => $request->input('description'),
                'qty' => $aggregate ? $aggregate['qty'] : $request->input('qty'),
                'sticker_qty' => $aggregate ? $aggregate['sticker_qty'] : $request->input('sticker_qty'),
                'art_status' => $request->input('art_status', 'pendente_arte'),
                'unit_price' => $aggregate ? $aggregate['unit_price'] : $request->input('unit_price'),
                'total' => $aggregate ? $aggregate['total'] : $request->input('total'),
                'status' => $request->input('status', 'recebido'),
                'payment_status' => $request->input('payment_status', 'pendente'),
                'payment_method' => $request->input('payment_method'),
                'payment_note' => $request->input('payment_note'),
                'delivery_method' => $request->input('delivery_method'),
                'delivery_date' => $request->input('delivery_date'),
                'deadline' => $request->input('deadline'),
                'responsible' => $request->input('responsible'),
                'notes' => $request->input('notes'),
            ]);

            if ($isMulti) {
                foreach ($items as $item) {
                    $this->createItem($order->id, $item);
                }
            }

            try {
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
            } catch (\Throwable $e) {
                Log::error('Falha ao criar ordem de producao do pedido ' . $order->id . ': ' . $e->getMessage());
            }

            try {
                GpDelivery::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'client_name' => $order->client_name,
                    'product_name' => $order->product_name,
                    'method' => $order->delivery_method,
                    'status' => 'pendente',
                    'scheduled_date' => $order->delivery_date,
                    'address' => $request->input('delivery_address'),
                ]);
            } catch (\Throwable $e) {
                Log::error('Falha ao criar entrega do pedido ' . $order->id . ': ' . $e->getMessage());
            }

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    try {
                        $path = $file->store('gp-orders/' . $order->id, 'public');
                        GpOrderFile::create([
                            'order_id' => $order->id,
                            'filename' => $file->getClientOriginalName() ?: 'arquivo',
                            'url' => Storage::url($path),
                            'mime_type' => $file->getMimeType(),
                            'size_bytes' => $file->getSize(),
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Falha ao salvar arquivo do pedido ' . $order->id . ': ' . $e->getMessage());
                    }
                }
            }

            return $order;
        });

        $order->load(['files', 'events', 'items']);

        return response()->json(['message' => 'Pedido criado com sucesso.', 'order' => $order], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $order = GpOrder::where('id', $id)->where('user_id', $user->id)->first();
        if (!$order) {
            return response()->json(['message' => 'Pedido nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'client_name' => ['sometimes', 'string', 'max:255'],
            'client_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'product_name' => ['sometimes', 'string', 'max:255'],
            'product_size' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'qty' => ['sometimes', 'integer', 'min:1'],
            'sticker_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'art_status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'total' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string'],
            'payment_status' => ['sometimes', 'string'],
            'payment_method' => ['sometimes', 'nullable', 'string'],
            'payment_note' => ['sometimes', 'nullable', 'string'],
            'delivery_method' => ['sometimes', 'nullable', 'string'],
            'delivery_date' => ['sometimes', 'nullable', 'date'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'responsible' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $items = $this->parseItems($request->input('items'));
        $itemErrors = $this->validateItems($items);
        if (!empty($itemErrors)) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $itemErrors], 422);
        }

        $oldStatus = $order->status;
        $oldArtStatus = $order->art_status;
        $data = $request->only([
            'client_name', 'client_phone', 'product_name', 'product_size', 'description',
            'qty', 'sticker_qty', 'art_status', 'unit_price', 'total', 'status', 'payment_status',
            'payment_method', 'payment_note', 'delivery_method', 'delivery_date',
            'deadline', 'responsible', 'notes',
        ]);

        $isMulti = count($items) > 0;
        if ($isMulti) {
            $aggregate = $this->aggregatesFromItems($items);
            $data['product_name'] = $aggregate['product_name'];
            $data['product_size'] = $aggregate['product_size'];
            $data['qty'] = $aggregate['qty'];
            $data['sticker_qty'] = $aggregate['sticker_qty'];
            $data['unit_price'] = $aggregate['unit_price'];
            $data['total'] = $aggregate['total'];
        }

        $order->update($data);

        if ($isMulti) {
            $order->items()->delete();
            foreach ($items as $item) {
                $this->createItem($order->id, $item);
            }
            GpOrderEvent::create([
                'order_id' => $order->id,
                'status' => $order->status,
                'note' => 'Produtos do pedido atualizados (' . count($items) . ' item(ns))',
                'created_by' => $user->name,
            ]);
        }

        if (isset($data['status']) && $data['status'] !== $oldStatus) {
            GpOrderEvent::create([
                'order_id' => $order->id,
                'status' => $data['status'],
                'note' => "Status alterado de '$oldStatus' para '{$data['status']}'",
                'created_by' => $user->name,
            ]);
        }

        if (isset($data['art_status']) && $data['art_status'] !== $oldArtStatus && $oldArtStatus !== null) {
            GpOrderEvent::create([
                'order_id' => $order->id,
                'status' => $order->status,
                'note' => "Arte alterada de '$oldArtStatus' para '{$data['art_status']}'",
                'created_by' => $user->name,
            ]);
        }

        $order->load(['files', 'events', 'items']);

        return response()->json(['message' => 'Pedido atualizado com sucesso.', 'order' => $order]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $order = GpOrder::where('id', $id)->where('user_id', $user->id)->first();
        if (!$order) {
            return response()->json(['message' => 'Pedido nao encontrado.'], 404);
        }

        DB::transaction(function () use ($order) {
            $order->events()->delete();
            $order->files()->delete();
            $order->productionOrders()->delete();
            $order->deliveries()->delete();
            $order->delete();
        });

        return response()->json(['message' => 'Pedido excluido com sucesso.'], 204);
    }

    private function parseItems(mixed $items): array
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($items) ? $items : [];
    }

    private function validateItems(array $items): array
    {
        $errors = [];
        foreach ($items as $idx => $item) {
            $label = 'items.' . $idx;
            if (empty(trim((string) ($item['product_name'] ?? '')))) {
                $errors[$label . '.product_name'] = 'O produto do item é obrigatório.';
            }
            $qty = (int) ($item['qty'] ?? 0);
            if ($qty < 1) {
                $errors[$label . '.qty'] = 'A quantidade do item deve ser pelo menos 1.';
            }
            $price = (float) ($item['unit_price'] ?? -1);
            if ($price < 0) {
                $errors[$label . '.unit_price'] = 'O preço unitário do item é inválido.';
            }
        }
        return $errors;
    }

    private function aggregatesFromItems(array $items): array
    {
        $totalQty = 0;
        $total = 0.0;
        $stickerQty = 0;
        $names = [];
        $sizes = [];

        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $price = round((float) ($item['unit_price'] ?? 0), 2);
            $totalQty += $qty;
            $total += round($qty * $price, 2);
            $stickerQty += (int) ($item['sticker_qty'] ?? 0);

            $name = trim((string) ($item['product_name'] ?? ''));
            $names[] = $qty > 1 ? ($qty . 'x ' . $name) : $name;

            $size = trim((string) ($item['product_size'] ?? ''));
            if ($size !== '' && !in_array($size, $sizes, true)) {
                $sizes[] = $size;
            }
        }

        return [
            'product_name' => mb_substr(implode(', ', $names), 0, 255),
            'product_size' => $sizes ? implode(', ', $sizes) : null,
            'qty' => $totalQty,
            'sticker_qty' => $stickerQty > 0 ? $stickerQty : null,
            'unit_price' => $totalQty > 0 ? round($total / $totalQty, 2) : 0,
            'total' => round($total, 2),
        ];
    }

    private function createItem(int $orderId, array $item): void
    {
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $price = round((float) ($item['unit_price'] ?? 0), 2);

        GpOrderItem::create([
            'order_id' => $orderId,
            'product_name' => trim((string) ($item['product_name'] ?? '')),
            'product_size' => !empty($item['product_size']) ? (string) $item['product_size'] : null,
            'description' => !empty($item['description']) ? (string) $item['description'] : null,
            'qty' => $qty,
            'sticker_qty' => !empty($item['sticker_qty']) ? (int) $item['sticker_qty'] : null,
            'unit_price' => $price,
            'total' => round($qty * $price, 2),
        ]);
    }
}
