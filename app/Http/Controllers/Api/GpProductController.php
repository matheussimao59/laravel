<?php

namespace App\Http\Controllers\Api;

use App\Models\GpProduct;
use App\Models\GpCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpProductController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $products = GpProduct::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['products' => $products]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:gp_categories,id'],
            'description' => ['nullable', 'string'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'pricing_type' => ['nullable', 'string', 'in:fixed,per_sheet'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'cost_materials' => ['nullable', 'numeric', 'min:0'],
            'cost_labor' => ['nullable', 'numeric', 'min:0'],
            'cost_fixed' => ['nullable', 'numeric', 'min:0'],
            'cost_other' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $product = GpProduct::create([
            'user_id' => $user->id,
            'name' => trim($request->input('name')),
            'category' => $request->input('category'),
            'category_id' => $request->input('category_id'),
            'description' => $request->input('description'),
            'sell_price' => $request->input('sell_price'),
            'pricing_type' => $request->input('pricing_type', 'fixed'),
            'stock_qty' => $request->input('stock_qty', 0),
            'cost_materials' => $request->input('cost_materials', 0),
            'cost_labor' => $request->input('cost_labor', 0),
            'cost_fixed' => $request->input('cost_fixed', 0),
            'cost_other' => $request->input('cost_other', 0),
            'active' => $request->boolean('active', true),
            'image_url' => $request->input('image_url'),
        ]);

        return response()->json(['message' => 'Produto criado com sucesso.', 'product' => $product], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $product = GpProduct::where('id', $id)->where('user_id', $user->id)->first();
        if (!$product) {
            return response()->json(['message' => 'Produto nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:gp_categories,id'],
            'description' => ['sometimes', 'nullable', 'string'],
            'sell_price' => ['sometimes', 'numeric', 'min:0'],
            'pricing_type' => ['sometimes', 'nullable', 'string', 'in:fixed,per_sheet'],
            'stock_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cost_materials' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_labor' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_fixed' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_other' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'image_url' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $product->update($request->only([
            'name', 'category', 'category_id', 'description', 'sell_price', 'pricing_type', 'stock_qty',
            'cost_materials', 'cost_labor', 'cost_fixed', 'cost_other',
            'active', 'image_url',
        ]));

        return response()->json(['message' => 'Produto atualizado com sucesso.', 'product' => $product]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $product = GpProduct::where('id', $id)->where('user_id', $user->id)->first();
        if (!$product) {
            return response()->json(['message' => 'Produto nao encontrado.'], 404);
        }

        $product->delete();

        return response()->json(['message' => 'Produto excluido com sucesso.'], 204);
    }
}
