<?php

namespace App\Http\Controllers\Api;

use App\Models\GpCategory;
use App\Models\GpProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpCategoryController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $categories = GpCategory::where('user_id', $user->id)
            ->withCount(['products as product_count'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $category = GpCategory::create([
            'user_id' => $user->id,
            'name' => trim($request->input('name')),
            'image_url' => $request->input('image_url'),
        ]);

        return response()->json(['message' => 'Categoria criada.', 'data' => $category], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $category = GpCategory::where('id', $id)->where('user_id', $user->id)->first();
        if (!$category) {
            return response()->json(['message' => 'Categoria nao encontrada.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'image_url' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $category->update($request->only(['name', 'image_url']));

        return response()->json(['message' => 'Categoria atualizada.', 'data' => $category]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $category = GpCategory::where('id', $id)->where('user_id', $user->id)->first();
        if (!$category) {
            return response()->json(['message' => 'Categoria nao encontrada.'], 404);
        }

        GpProduct::where('category_id', $category->id)->update(['category_id' => null]);

        $category->delete();

        return response()->json(['message' => 'Categoria excluida.']);
    }
}
