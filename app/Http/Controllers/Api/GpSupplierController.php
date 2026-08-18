<?php

namespace App\Http\Controllers\Api;

use App\Models\GpSupplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpSupplierController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $suppliers = GpSupplier::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['suppliers' => $suppliers]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'products' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $supplier = GpSupplier::create([
            'user_id' => $user->id,
            'name' => trim($request->input('name')),
            'cnpj' => $request->input('cnpj'),
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            'address' => $request->input('address'),
            'products' => $request->input('products'),
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Fornecedor criado com sucesso.', 'supplier' => $supplier], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $supplier = GpSupplier::where('id', $id)->where('user_id', $user->id)->first();
        if (!$supplier) {
            return response()->json(['message' => 'Fornecedor nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'products' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $supplier->update($request->only(['name', 'cnpj', 'phone', 'email', 'address', 'products', 'notes']));

        return response()->json(['message' => 'Fornecedor atualizado com sucesso.', 'supplier' => $supplier]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $supplier = GpSupplier::where('id', $id)->where('user_id', $user->id)->first();
        if (!$supplier) {
            return response()->json(['message' => 'Fornecedor nao encontrado.'], 404);
        }

        $supplier->delete();

        return response()->json(['message' => 'Fornecedor excluido com sucesso.'], 204);
    }
}
