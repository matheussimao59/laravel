<?php

namespace App\Http\Controllers\Api;

use App\Models\GpProduct;
use App\Models\GpSale;
use App\Models\GpSaleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GpSaleController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $sales = GpSale::where('user_id', $user->id)
            ->with(['items', 'items.product'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['sales' => $sales]);
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
            'delivery_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $sale = DB::transaction(function () use ($request, $user) {
            $sale = GpSale::create([
                'user_id' => $user->id,
                'client_name' => trim($request->input('client_name')),
                'client_phone' => $request->input('client_phone'),
                'delivery_date' => $request->input('delivery_date'),
                'payment_method' => $request->input('payment_method'),
                'discount' => $request->input('discount', 0),
                'total' => $request->input('total'),
            ]);

            foreach ($request->input('items') as $item) {
                $product = null;
                if (!empty($item['product_id'])) {
                    $product = GpProduct::find($item['product_id']);
                }

                $unitPrice = (float) $item['unit_price'];
                $qty = (int) $item['qty'];

                GpSaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product?->id ?? ($item['product_id'] ?? null),
                    'product_name' => $product?->name ?? ($item['product_name'] ?? 'Produto'),
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => round($unitPrice * $qty, 2),
                ]);
            }

            return $sale;
        });

        $sale->load(['items', 'items.product']);

        return response()->json(['message' => 'Venda registrada com sucesso.', 'sale' => $sale], 201);
    }
}