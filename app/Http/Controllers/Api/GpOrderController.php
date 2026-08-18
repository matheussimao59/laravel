<?php

namespace App\Http\Controllers\Api;

use App\Models\GpOrder;
use App\Models\GpOrderEvent;
use App\Models\GpProductionOrder;
use App\Models\GpDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['files', 'events'])
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
            'product_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'qty' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
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

        $order = DB::transaction(function () use ($request, $user) {
            $order = GpOrder::create([
                'user_id' => $user->id,
                'quote_id' => $request->input('quote_id'),
                'client_id' => $request->input('client_id'),
                'client_name' => trim($request->input('client_name')),
                'client_phone' => $request->input('client_phone'),
                'product_name' => trim($request->input('product_name')),
                'description' => $request->input('description'),
                'qty' => $request->input('qty'),
                'unit_price' => $request->input('unit_price'),
                'total' => $request->input('total'),
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
                'method' => $order->delivery_method,
                'status' => 'pendente',
                'scheduled_date' => $order->delivery_date,
                'address' => $request->input('delivery_address'),
            ]);

            return $order;
        });

        $order->load(['files', 'events']);

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
            'description' => ['sometimes', 'nullable', 'string'],
            'qty' => ['sometimes', 'integer', 'min:1'],
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

        $oldStatus = $order->status;
        $data = $request->only([
            'client_name', 'client_phone', 'product_name', 'description',
            'qty', 'unit_price', 'total', 'status', 'payment_status',
            'payment_method', 'payment_note', 'delivery_method', 'delivery_date',
            'deadline', 'responsible', 'notes',
        ]);

        $order->update($data);

        if (isset($data['status']) && $data['status'] !== $oldStatus) {
            GpOrderEvent::create([
                'order_id' => $order->id,
                'status' => $data['status'],
                'note' => "Status alterado de '$oldStatus' para '{$data['status']}'",
                'created_by' => $user->name,
            ]);
        }

        $order->load(['files', 'events']);

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
}
