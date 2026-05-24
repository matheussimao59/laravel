<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PricingProductController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $rows = DB::table('pricing_products')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->values();

        return response()->json(['products' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'product_name' => ['required', 'string', 'max:190'],
            'product_image_data' => ['nullable', 'string'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'base_cost' => ['nullable', 'numeric', 'min:0'],
            'final_margin' => ['nullable', 'numeric'],
            'materials_json' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para produto.', 'errors' => $validator->errors()], 422);
        }

        $id = DB::table('pricing_products')->insertGetId([
            'user_id' => $user->id,
            'product_name' => trim((string) $request->input('product_name')),
            'product_image_data' => $request->input('product_image_data'),
            'selling_price' => (float) $request->input('selling_price', 0),
            'base_cost' => (float) $request->input('base_cost', 0),
            'final_margin' => (float) $request->input('final_margin', 0),
            'materials_json' => $request->has('materials_json') ? json_encode($request->input('materials_json')) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('pricing_products')->where('id', $id)->first();

        return response()->json([
            'message' => 'Produto salvo com sucesso.',
            'product' => $row ? $this->mapRow($row) : null,
        ], 201);
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $row = $this->findOwnedRow($request, $product);
        if (!$row) {
            return response()->json(['message' => 'Produto nao encontrado.'], 404);
        }

        return response()->json(['product' => $this->mapRow($row)]);
    }

    public function update(Request $request, string $product): JsonResponse
    {
        $row = $this->findOwnedRow($request, $product);
        if (!$row) {
            return response()->json(['message' => 'Produto nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'product_name' => ['sometimes', 'string', 'max:190'],
            'product_image_data' => ['sometimes', 'nullable', 'string'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'base_cost' => ['sometimes', 'numeric', 'min:0'],
            'final_margin' => ['sometimes', 'numeric'],
            'materials_json' => ['sometimes', 'nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para produto.', 'errors' => $validator->errors()], 422);
        }

        DB::table('pricing_products')->where('id', (int) $product)->update([
            'product_name' => $request->has('product_name') ? trim((string) $request->input('product_name')) : $row->product_name,
            'product_image_data' => $request->has('product_image_data') ? $request->input('product_image_data') : $row->product_image_data,
            'selling_price' => $request->has('selling_price') ? (float) $request->input('selling_price') : $row->selling_price,
            'base_cost' => $request->has('base_cost') ? (float) $request->input('base_cost') : $row->base_cost,
            'final_margin' => $request->has('final_margin') ? (float) $request->input('final_margin') : $row->final_margin,
            'materials_json' => $request->has('materials_json')
                ? json_encode($request->input('materials_json'))
                : $row->materials_json,
            'updated_at' => now(),
        ]);

        $updated = DB::table('pricing_products')->where('id', (int) $product)->first();

        return response()->json([
            'message' => 'Produto atualizado com sucesso.',
            'product' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $product): JsonResponse
    {
        $row = $this->findOwnedRow($request, $product);
        if (!$row) {
            return response()->json(['message' => 'Produto nao encontrado.'], 404);
        }

        DB::table('pricing_products')->where('id', (int) $product)->delete();

        return response()->json(['message' => 'Produto excluido com sucesso.', 'id' => $product]);
    }

    private function findOwnedRow(Request $request, string $product): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('pricing_products')
            ->where('id', (int) $product)
            ->where('user_id', $user->id)
            ->first();
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'product_name' => $row->product_name,
            'product_image_data' => $row->product_image_data,
            'selling_price' => (float) $row->selling_price,
            'base_cost' => (float) $row->base_cost,
            'final_margin' => (float) $row->final_margin,
            'materials_json' => $this->decodeJson($row->materials_json),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return $value;
    }
}
