<?php

namespace App\Http\Controllers\Api;

use App\Models\GpProduct;
use App\Models\GpCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GpProductController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = GpProduct::where('user_id', $user->id)->orderByDesc('created_at');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->with(['materials' => function ($q) {
            $q->withPivot('qty_needed', 'cost_override');
        }, 'cuttingMachine', 'discountTiers'])->get();

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
            'sku' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:gp_categories,id'],
            'description' => ['nullable', 'string'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'pricing_type' => ['nullable', 'string', 'in:fixed,per_sheet'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'cost_materials' => ['nullable', 'numeric', 'min:0'],
            'cost_labor' => ['nullable', 'numeric', 'min:0'],
            'cost_fixed' => ['nullable', 'numeric', 'min:0'],
            'cost_other' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string'],
            'cut_shape' => ['nullable', 'string', 'in:round,square,rectangle'],
            'cut_width' => ['nullable', 'numeric', 'min:0'],
            'cut_height' => ['nullable', 'numeric', 'min:0'],
            'cutting_machine_id' => ['nullable', 'integer', 'exists:gp_cutting_machines,id'],
            'art_image_url' => ['nullable', 'string'],
            'sale_channels_json' => ['nullable', 'array'],
            'materials' => ['nullable', 'array'],
            'materials.*.material_id' => ['required_with:materials', 'integer', 'exists:gp_materials,id'],
            'materials.*.qty_needed' => ['required_with:materials', 'numeric', 'min:0.001'],
            'materials.*.cost_override' => ['nullable', 'numeric', 'min:0'],
            'discount_tiers' => ['nullable', 'array'],
            'discount_tiers.*.min_qty' => ['required_with:discount_tiers', 'integer', 'min:1'],
            'discount_tiers.*.unit_price' => ['required_with:discount_tiers', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $product = GpProduct::create([
                'user_id' => $user->id,
                'name' => trim($request->input('name')),
                'sku' => $request->input('sku'),
                'category' => $request->input('category'),
                'category_id' => $request->input('category_id'),
                'description' => $request->input('description'),
                'sell_price' => $request->input('sell_price'),
                'pricing_type' => $request->input('pricing_type', 'fixed'),
                'stock_qty' => $request->input('stock_qty', 0),
                'unit' => $request->input('unit', 'un'),
                'cost_materials' => $request->input('cost_materials', 0),
                'cost_labor' => $request->input('cost_labor', 0),
                'cost_fixed' => $request->input('cost_fixed', 0),
                'cost_other' => $request->input('cost_other', 0),
                'active' => $request->boolean('active', true),
                'image_url' => $request->input('image_url'),
                'cut_shape' => $request->input('cut_shape'),
                'cut_width' => $request->input('cut_width'),
                'cut_height' => $request->input('cut_height'),
                'cutting_machine_id' => $request->input('cutting_machine_id'),
                'art_image_url' => $request->input('art_image_url'),
                'sale_channels_json' => $request->input('sale_channels_json'),
            ]);

            if ($request->has('materials')) {
                $materialData = [];
                foreach ($request->input('materials', []) as $m) {
                    $materialData[$m['material_id']] = [
                        'qty_needed' => $m['qty_needed'],
                        'cost_override' => $m['cost_override'] ?? null,
                    ];
                }
                $product->materials()->sync($materialData);
            }

            if ($request->has('discount_tiers')) {
                $this->syncDiscountTiers($product, $request);
            }

            DB::commit();

            $product->load(['materials', 'discountTiers']);

            return response()->json(['message' => 'Produto criado com sucesso.', 'product' => $product], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao criar produto: ' . $e->getMessage()], 500);
        }
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
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:gp_categories,id'],
            'description' => ['sometimes', 'nullable', 'string'],
            'sell_price' => ['sometimes', 'numeric', 'min:0'],
            'pricing_type' => ['sometimes', 'nullable', 'string', 'in:fixed,per_sheet'],
            'stock_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'cost_materials' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_labor' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_fixed' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_other' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'image_url' => ['sometimes', 'nullable', 'string'],
            'cut_shape' => ['sometimes', 'nullable', 'string', 'in:round,square,rectangle'],
            'cut_width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cut_height' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cutting_machine_id' => ['sometimes', 'nullable', 'integer', 'exists:gp_cutting_machines,id'],
            'art_image_url' => ['sometimes', 'nullable', 'string'],
            'sale_channels_json' => ['nullable', 'array'],
            'materials' => ['nullable', 'array'],
            'materials.*.material_id' => ['required_with:materials', 'integer', 'exists:gp_materials,id'],
            'materials.*.qty_needed' => ['required_with:materials', 'numeric', 'min:0.001'],
            'materials.*.cost_override' => ['nullable', 'numeric', 'min:0'],
            'discount_tiers' => ['nullable', 'array'],
            'discount_tiers.*.min_qty' => ['required_with:discount_tiers', 'integer', 'min:1'],
            'discount_tiers.*.unit_price' => ['required_with:discount_tiers', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $product->update($request->only([
                'name', 'sku', 'category', 'category_id', 'description', 'sell_price', 'pricing_type', 'stock_qty',
                'unit', 'cost_materials', 'cost_labor', 'cost_fixed', 'cost_other',
                'active', 'image_url',
                'cut_shape', 'cut_width', 'cut_height', 'cutting_machine_id', 'art_image_url',
                'sale_channels_json',
            ]));

            if ($request->has('materials')) {
                $materialData = [];
                foreach ($request->input('materials', []) as $m) {
                    $materialData[$m['material_id']] = [
                        'qty_needed' => $m['qty_needed'],
                        'cost_override' => $m['cost_override'] ?? null,
                    ];
                }
                $product->materials()->sync($materialData);
            }

            if ($request->has('discount_tiers')) {
                $this->syncDiscountTiers($product, $request);
            }

            DB::commit();

            $product->load(['materials', 'discountTiers']);

            return response()->json(['message' => 'Produto atualizado com sucesso.', 'product' => $product]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao atualizar produto: ' . $e->getMessage()], 500);
        }
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

        $product->materials()->detach();
        $product->discountTiers()->delete();
        $product->delete();

        return response()->json(['message' => 'Produto excluido com sucesso.'], 204);
    }

    private function syncDiscountTiers(GpProduct $product, Request $request): void
    {
        $tiers = collect($request->input('discount_tiers', []))
            ->filter(fn ($t) => (int) ($t['min_qty'] ?? 0) >= 1 && (float) ($t['unit_price'] ?? 0) > 0)
            ->map(fn ($t) => [
                'min_qty' => (int) $t['min_qty'],
                'unit_price' => (float) $t['unit_price'],
            ])
            ->values();

        $product->discountTiers()->delete();
        $product->discountTiers()->createMany($tiers);
    }
}