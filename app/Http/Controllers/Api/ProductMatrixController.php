<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ProductMatrixController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $rows = DB::table('product_matrices')
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->values();

        return response()->json(['matrices' => $rows]);
    }

    public function show(Request $request, string $matrix): JsonResponse
    {
        $row = $this->findOwnedRow($request, $matrix);
        if (!$row) {
            return response()->json(['message' => 'Matriz nao encontrada.'], 404);
        }

        return response()->json(['matrix' => $this->mapRow($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $payload = $this->validatePayload($request, false);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $id = DB::table('product_matrices')->insertGetId([
            'user_id' => $user->id,
            'name' => $payload['name'],
            'image_data' => $payload['image_data'],
            'orientation' => $payload['orientation'],
            'sheet_size' => $payload['sheet_size'],
            'fill_sheet' => $payload['fill_sheet'],
            'item_width_mm' => $payload['item_width_mm'],
            'item_height_mm' => $payload['item_height_mm'],
            'fields_json' => json_encode($payload['fields'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('product_matrices')->where('id', $id)->first();

        return response()->json([
            'message' => 'Matriz criada com sucesso.',
            'matrix' => $row ? $this->mapRow($row) : null,
        ], 201);
    }

    public function update(Request $request, string $matrix): JsonResponse
    {
        $row = $this->findOwnedRow($request, $matrix);
        if (!$row) {
            return response()->json(['message' => 'Matriz nao encontrada.'], 404);
        }

        $payload = $this->validatePayload($request, true);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $currentFields = json_decode((string) $row->fields_json, true);

        DB::table('product_matrices')
            ->where('id', (int) $matrix)
            ->update([
                'name' => $payload['name'] ?? $row->name,
                'image_data' => $payload['image_data'] ?? $row->image_data,
                'orientation' => $payload['orientation'] ?? $row->orientation,
                'sheet_size' => $payload['sheet_size'] ?? $row->sheet_size,
                'fill_sheet' => array_key_exists('fill_sheet', $payload) ? $payload['fill_sheet'] : (bool) $row->fill_sheet,
                'item_width_mm' => $payload['item_width_mm'] ?? (float) $row->item_width_mm,
                'item_height_mm' => $payload['item_height_mm'] ?? (float) $row->item_height_mm,
                'fields_json' => json_encode($payload['fields'] ?? (is_array($currentFields) ? $currentFields : []), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        $updated = DB::table('product_matrices')->where('id', (int) $matrix)->first();

        return response()->json([
            'message' => 'Matriz atualizada com sucesso.',
            'matrix' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $matrix): JsonResponse
    {
        $row = $this->findOwnedRow($request, $matrix);
        if (!$row) {
            return response()->json(['message' => 'Matriz nao encontrada.'], 404);
        }

        DB::table('product_matrices')->where('id', (int) $matrix)->delete();

        return response()->json(['message' => 'Matriz excluida com sucesso.', 'id' => $matrix]);
    }

    private function findOwnedRow(Request $request, string $matrix): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('product_matrices')->where('id', (int) $matrix)->where('user_id', $user->id)->first();
    }

    private function mapRow(object $row): array
    {
        $fields = json_decode((string) $row->fields_json, true);

        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'name' => $row->name,
            'image_data' => $row->image_data,
            'orientation' => $row->orientation,
            'sheet_size' => $row->sheet_size,
            'fill_sheet' => (bool) $row->fill_sheet,
            'item_width_mm' => (float) $row->item_width_mm,
            'item_height_mm' => (float) $row->item_height_mm,
            'fields' => is_array($fields) ? array_values($fields) : [],
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function validatePayload(Request $request, bool $partial): array|JsonResponse
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'],
            'image_data' => [$partial ? 'sometimes' : 'required', 'string'],
            'orientation' => [$partial ? 'sometimes' : 'required', 'in:portrait,landscape'],
            'sheet_size' => [$partial ? 'sometimes' : 'required', 'in:A4,A3,LETTER'],
            'fill_sheet' => [$partial ? 'sometimes' : 'required', 'boolean'],
            'item_width_mm' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:10', 'max:1000'],
            'item_height_mm' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:10', 'max:1000'],
            'fields' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'fields.*.id' => ['required_with:fields', 'string', 'max:80'],
            'fields.*.name' => ['required_with:fields', 'string', 'max:120'],
            'fields.*.sampleText' => ['nullable', 'string', 'max:5000'],
            'fields.*.x' => ['required_with:fields', 'numeric', 'min:0', 'max:100'],
            'fields.*.y' => ['required_with:fields', 'numeric', 'min:0', 'max:100'],
            'fields.*.width' => ['required_with:fields', 'numeric', 'min:1', 'max:100'],
            'fields.*.height' => ['required_with:fields', 'numeric', 'min:1', 'max:100'],
            'fields.*.fontSize' => ['required_with:fields', 'numeric', 'min:1', 'max:50'],
            'fields.*.fontWeight' => ['required_with:fields', 'numeric', 'min:100', 'max:900'],
            'fields.*.align' => ['required_with:fields', 'in:left,center,right'],
            'fields.*.textColor' => ['nullable', 'string', 'max:20'],
            'fields.*.strokeColor' => ['nullable', 'string', 'max:20'],
            'fields.*.strokeWidth' => ['nullable', 'numeric', 'min:0', 'max:8'],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados invalidos para matriz.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var array<string,mixed> $validated */
        $validated = $validator->validated();
        return $validated;
    }
}
