<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PricingMaterialController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $rows = DB::table('pricing_materials')
            ->where(function ($builder) use ($user) {
                $builder->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->values();

        return response()->json(['materials' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'unit_of_measure' => ['nullable', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para material.', 'errors' => $validator->errors()], 422);
        }

        $unitCost = (float) ($request->input('unit_cost') ?? $request->input('cost_per_unit') ?? 0);
        $id = DB::table('pricing_materials')->insertGetId([
            'user_id' => $user->id,
            'name' => trim((string) $request->input('name')),
            'unit_cost' => $unitCost,
            'cost_per_unit' => $unitCost,
            'unit_of_measure' => trim((string) $request->input('unit_of_measure', 'un')) ?: 'un',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('pricing_materials')->where('id', $id)->first();

        return response()->json([
            'message' => 'Material salvo com sucesso.',
            'material' => $row ? $this->mapRow($row) : null,
        ], 201);
    }

    public function show(Request $request, string $material): JsonResponse
    {
        $row = $this->findOwnedRow($request, $material);
        if (!$row) {
            return response()->json(['message' => 'Material nao encontrado.'], 404);
        }

        return response()->json(['material' => $this->mapRow($row)]);
    }

    public function update(Request $request, string $material): JsonResponse
    {
        $row = $this->findOwnedRow($request, $material);
        if (!$row) {
            return response()->json(['message' => 'Material nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:160'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'cost_per_unit' => ['sometimes', 'numeric', 'min:0'],
            'unit_of_measure' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para material.', 'errors' => $validator->errors()], 422);
        }

        $unitCost = $request->has('unit_cost')
            ? (float) $request->input('unit_cost')
            : (float) ($request->input('cost_per_unit') ?? $row->unit_cost ?? 0);

        DB::table('pricing_materials')->where('id', (int) $material)->update([
            'name' => $request->has('name') ? trim((string) $request->input('name')) : $row->name,
            'unit_cost' => $unitCost,
            'cost_per_unit' => $unitCost,
            'unit_of_measure' => $request->has('unit_of_measure')
                ? (trim((string) $request->input('unit_of_measure')) ?: 'un')
                : $row->unit_of_measure,
            'updated_at' => now(),
        ]);

        $updated = DB::table('pricing_materials')->where('id', (int) $material)->first();

        return response()->json([
            'message' => 'Material atualizado com sucesso.',
            'material' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $material): JsonResponse
    {
        $row = $this->findOwnedRow($request, $material);
        if (!$row) {
            return response()->json(['message' => 'Material nao encontrado.'], 404);
        }

        DB::table('pricing_materials')->where('id', (int) $material)->delete();

        return response()->json(['message' => 'Material excluido com sucesso.', 'id' => $material]);
    }

    private function findOwnedRow(Request $request, string $material): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('pricing_materials')
            ->where('id', (int) $material)
            ->where(function ($builder) use ($user) {
                $builder->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->first();
    }

    private function mapRow(object $row): array
    {
        $unitCost = (float) ($row->unit_cost ?? $row->cost_per_unit ?? 0);

        return [
            'id' => (string) $row->id,
            'user_id' => $row->user_id ? (string) $row->user_id : null,
            'name' => $row->name,
            'unit_cost' => $unitCost,
            'cost_per_unit' => $unitCost,
            'unit_of_measure' => $row->unit_of_measure ?: 'un',
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
