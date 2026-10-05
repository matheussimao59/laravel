<?php

namespace App\Http\Controllers\Api;

use App\Models\GpArtePlotter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpArtePlotterController
{
    /** Colunas leves para a listagem (evita baixar o JSON de items com data URLs). */
    private const SUMMARY_COLUMNS = [
        'id', 'name', 'machine', 'sheet_format', 'orientation',
        'sheet_width_mm', 'sheet_height_mm', 'item_count', 'created_at', 'updated_at',
    ];

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $nullable = $creating ? 'nullable' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'machine' => ['nullable', 'string', 'max:255'],
            'sheet_format' => [$nullable, 'string', 'in:a4,a3'],
            'orientation' => [$nullable, 'string', 'in:retrato,paisagem'],
            'sheet_width_mm' => [$nullable, 'numeric', 'min:0', 'max:5000'],
            'sheet_height_mm' => [$nullable, 'numeric', 'min:0', 'max:5000'],
            'items' => [$nullable, 'array', 'max:500'],
            'items.*.id' => ['required', 'string', 'max:80'],
            'items.*.kind' => ['required', 'string', 'in:texto,imagem,forma'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.xMm' => ['required', 'numeric', 'min:0', 'max:5000'],
            'items.*.yMm' => ['required', 'numeric', 'min:0', 'max:5000'],
            'items.*.wMm' => ['required', 'numeric', 'min:0', 'max:5000'],
            'items.*.hMm' => ['required', 'numeric', 'min:0', 'max:5000'],
            'items.*.rotationDeg' => ['nullable', 'numeric', 'min:-360', 'max:360'],
            'items.*.zIndex' => ['nullable', 'integer'],
            'items.*.text' => ['nullable', 'string', 'max:2000'],
            'items.*.src' => ['nullable', 'string'],
        ];
    }

    private function find(Request $request, string $id): ?GpArtePlotter
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return GpArtePlotter::where('id', $id)->where('user_id', $user->id)->first();
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $arts = GpArtePlotter::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get(self::SUMMARY_COLUMNS);

        return response()->json(['arts' => $arts]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $items = $request->input('items', []);

        $art = GpArtePlotter::create([
            'user_id' => $user->id,
            'name' => trim((string) $request->input('name')),
            'machine' => $request->input('machine'),
            'sheet_format' => $request->input('sheet_format', 'a4'),
            'orientation' => $request->input('orientation', 'retrato'),
            'sheet_width_mm' => $request->input('sheet_width_mm', 0),
            'sheet_height_mm' => $request->input('sheet_height_mm', 0),
            'items' => $items,
            'item_count' => count($items),
        ]);

        return response()->json(['message' => 'Arte criada com sucesso.', 'art' => $art], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $art = $this->find($request, $id);
        if (!$art) {
            return response()->json(['message' => 'Arte nao encontrada.'], 404);
        }

        return response()->json(['art' => $art]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $art = $this->find($request, $id);
        if (!$art) {
            return response()->json(['message' => 'Arte nao encontrada.'], 404);
        }

        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $payload = [];
        foreach (['name', 'machine', 'sheet_format', 'orientation', 'sheet_width_mm', 'sheet_height_mm'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }
        if (isset($payload['name'])) {
            $payload['name'] = trim((string) $payload['name']);
        }
        if ($request->has('items')) {
            $items = $request->input('items', []);
            $payload['items'] = $items;
            $payload['item_count'] = count($items);
        }

        $art->update($payload);

        return response()->json(['message' => 'Arte atualizada com sucesso.', 'art' => $art->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $art = $this->find($request, $id);
        if (!$art) {
            return response()->json(['message' => 'Arte nao encontrada.'], 404);
        }

        $art->delete();

        return response()->json(['message' => 'Arte excluida com sucesso.'], 204);
    }
}
