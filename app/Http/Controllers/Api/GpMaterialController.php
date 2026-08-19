<?php

namespace App\Http\Controllers\Api;

use App\Models\GpMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpMaterialController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = GpMaterial::where('user_id', $user->id)->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        $materials = $query->get();

        return response()->json(['materials' => $materials]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:gp_suppliers,id'],
            'image_url' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $material = GpMaterial::create([
            'user_id' => $user->id,
            'name' => trim($request->input('name')),
            'unit' => $request->input('unit', 'un'),
            'unit_cost' => $request->input('unit_cost'),
            'stock_qty' => $request->input('stock_qty', 0),
            'min_stock' => $request->input('min_stock', 0),
            'supplier_id' => $request->input('supplier_id'),
            'image_url' => $request->input('image_url'),
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Material criado com sucesso.', 'material' => $material], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $material = GpMaterial::where('id', $id)->where('user_id', $user->id)->first();
        if (!$material) {
            return response()->json(['message' => 'Material nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'stock_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:gp_suppliers,id'],
            'image_url' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $material->update($request->only([
            'name', 'unit', 'unit_cost', 'stock_qty', 'min_stock',
            'supplier_id', 'image_url', 'notes',
        ]));

        return response()->json(['message' => 'Material atualizado com sucesso.', 'material' => $material]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $material = GpMaterial::where('id', $id)->where('user_id', $user->id)->first();
        if (!$material) {
            return response()->json(['message' => 'Material nao encontrado.'], 404);
        }

        $material->products()->detach();
        $material->delete();

        return response()->json(['message' => 'Material excluido com sucesso.'], 204);
    }
}