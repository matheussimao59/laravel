<?php

namespace App\Http\Controllers\Api;

use App\Models\GpProductTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpProductTemplateController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $templates = GpProductTemplate::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['product_templates' => $templates]);
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
            'description' => ['nullable', 'string'],
            'width_mm' => ['nullable', 'numeric', 'min:0'],
            'height_mm' => ['nullable', 'numeric', 'min:0'],
            'material' => ['nullable', 'string', 'max:255'],
            'acabamento' => ['nullable', 'string', 'max:255'],
            'default_qty' => ['nullable', 'integer', 'min:0'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'cost_material' => ['nullable', 'numeric', 'min:0'],
            'cost_labor' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $template = GpProductTemplate::create([
            'user_id' => $user->id,
            'name' => trim($request->input('name')),
            'category' => $request->input('category'),
            'description' => $request->input('description'),
            'width_mm' => $request->input('width_mm'),
            'height_mm' => $request->input('height_mm'),
            'material' => $request->input('material'),
            'acabamento' => $request->input('acabamento'),
            'default_qty' => $request->input('default_qty'),
            'base_price' => $request->input('base_price'),
            'cost_material' => $request->input('cost_material', 0),
            'cost_labor' => $request->input('cost_labor', 0),
            'image_url' => $request->input('image_url'),
            'active' => $request->boolean('active', true),
        ]);

        return response()->json(['message' => 'Modelo de produto criado com sucesso.', 'product_template' => $template], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $template = GpProductTemplate::where('id', $id)->where('user_id', $user->id)->first();
        if (!$template) {
            return response()->json(['message' => 'Modelo de produto nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'width_mm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'height_mm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'material' => ['sometimes', 'nullable', 'string', 'max:255'],
            'acabamento' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'cost_material' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_labor' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'image_url' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $template->update($request->only([
            'name', 'category', 'description', 'width_mm', 'height_mm',
            'material', 'acabamento', 'default_qty', 'base_price',
            'cost_material', 'cost_labor', 'image_url', 'active',
        ]));

        return response()->json(['message' => 'Modelo de produto atualizado com sucesso.', 'product_template' => $template]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $template = GpProductTemplate::where('id', $id)->where('user_id', $user->id)->first();
        if (!$template) {
            return response()->json(['message' => 'Modelo de produto nao encontrado.'], 404);
        }

        $template->delete();

        return response()->json(['message' => 'Modelo de produto excluido com sucesso.'], 204);
    }
}
