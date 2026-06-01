<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class ManualPrintOrderController
{
    private const STATUSES = [
        'Dados Pendente',
        'Pronto p/ Impressão',
        'Impresso',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = DB::table('manual_print_orders')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->filled('status') && in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'orders' => $query->get()->map(fn ($row) => $this->mapRow($row))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'model_id' => ['nullable', 'integer', 'exists:modelos,id'],
            'platform_order_id' => ['nullable', 'string', 'max:120'],
            'is_group_order' => ['nullable', 'boolean'],
            'group_size' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'values' => ['nullable', 'array'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'status' => ['nullable', 'string', 'in:' . implode(',', self::STATUSES)],
            'saved_at' => ['nullable', 'date'],
            'printed_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para pedido de impressao.', 'errors' => $validator->errors()], 422);
        }

        $modelId = $request->input('model_id');
        if ($modelId && !$this->modelBelongsToUser((int) $modelId, (int) $user->id)) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        $platformOrderId = $request->filled('platform_order_id') ? trim((string) $request->input('platform_order_id')) : null;
        $isGroupOrder = $request->boolean('is_group_order');
        if ($platformOrderId && !$isGroupOrder && $this->hasPlatformOrderIdColumn() && $this->platformOrderExists((int) $user->id, $platformOrderId)) {
            return response()->json(['message' => 'Pedido da plataforma ja importado.'], 409);
        }

        $insertData = [
            'user_id' => $user->id,
            'modelo_id' => $modelId ? (int) $modelId : null,
            'values' => json_encode($request->input('values', [])),
            'quantity' => (int) $request->input('quantity', 1),
            'status' => $request->input('status', 'Dados Pendente'),
            'saved_at' => $this->normalizeTimestamp($request->input('saved_at')),
            'printed_at' => $this->normalizeTimestamp($request->input('printed_at')),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($this->hasPlatformOrderIdColumn()) {
            $insertData['platform_order_id'] = $platformOrderId;
        }
        if ($this->hasColumn('is_group_order')) {
            $insertData['is_group_order'] = $isGroupOrder;
        }
        if ($this->hasColumn('group_size')) {
            $insertData['group_size'] = $request->filled('group_size') ? (int) $request->input('group_size') : null;
        }

        $id = DB::table('manual_print_orders')->insertGetId($insertData);

        $row = DB::table('manual_print_orders')->where('id', $id)->first();

        return response()->json([
            'message' => 'Pedido de impressao criado com sucesso.',
            'order' => $row ? $this->mapRow($row) : null,
        ], 201);
    }

    public function update(Request $request, string $order): JsonResponse
    {
        $row = $this->findOwnedRow($request, $order);
        if (!$row) {
            return response()->json(['message' => 'Pedido de impressao nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'model_id' => ['sometimes', 'nullable', 'integer', 'exists:modelos,id'],
            'platform_order_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_group_order' => ['sometimes', 'nullable', 'boolean'],
            'group_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'values' => ['sometimes', 'array'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'status' => ['sometimes', 'string', 'in:' . implode(',', self::STATUSES)],
            'saved_at' => ['sometimes', 'nullable', 'date'],
            'printed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para pedido de impressao.', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $modelId = $request->has('model_id') ? $request->input('model_id') : $row->modelo_id;
        if ($modelId && !$this->modelBelongsToUser((int) $modelId, (int) $user->id)) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        $updateData = [
            'modelo_id' => $modelId ? (int) $modelId : null,
            'values' => $request->has('values') ? json_encode($request->input('values', [])) : $row->values,
            'quantity' => $request->has('quantity') ? (int) $request->input('quantity') : (int) $row->quantity,
            'status' => $request->has('status') ? $request->input('status') : $row->status,
            'saved_at' => $request->exists('saved_at') ? $this->normalizeTimestamp($request->input('saved_at')) : $row->saved_at,
            'printed_at' => $request->exists('printed_at') ? $this->normalizeTimestamp($request->input('printed_at')) : $row->printed_at,
            'updated_at' => now(),
        ];

        if ($this->hasPlatformOrderIdColumn()) {
            $updateData['platform_order_id'] = $request->has('platform_order_id')
                ? ($request->filled('platform_order_id') ? trim((string) $request->input('platform_order_id')) : null)
                : ($row->platform_order_id ?? null);
        }
        if ($this->hasColumn('is_group_order')) {
            $updateData['is_group_order'] = $request->has('is_group_order') ? $request->boolean('is_group_order') : (bool) ($row->is_group_order ?? false);
        }
        if ($this->hasColumn('group_size')) {
            $updateData['group_size'] = $request->has('group_size') ? ($request->filled('group_size') ? (int) $request->input('group_size') : null) : ($row->group_size ?? null);
        }

        DB::table('manual_print_orders')->where('id', (int) $order)->update($updateData);

        $updated = DB::table('manual_print_orders')->where('id', (int) $order)->first();

        return response()->json([
            'message' => 'Pedido de impressao atualizado com sucesso.',
            'order' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $order): JsonResponse
    {
        $row = $this->findOwnedRow($request, $order);
        if (!$row) {
            return response()->json(['message' => 'Pedido de impressao nao encontrado.'], 404);
        }

        DB::table('manual_print_orders')->where('id', (int) $order)->delete();

        return response()->json(['message' => 'Pedido de impressao excluido com sucesso.', 'id' => $order]);
    }

    private function findOwnedRow(Request $request, string $order): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('manual_print_orders')
            ->where('id', (int) $order)
            ->where('user_id', $user->id)
            ->first();
    }

    private function modelBelongsToUser(int $modelId, int $userId): bool
    {
        $query = DB::table('modelos')
            ->where('id', $modelId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId);

                if (Schema::hasColumn('modelos', 'is_shared')) {
                    $query->orWhere('is_shared', true);
                }

                if (Schema::hasTable('modelo_user_accesses')) {
                    $query->orWhereExists(function ($subQuery) use ($userId) {
                        $subQuery->selectRaw('1')
                            ->from('modelo_user_accesses')
                            ->whereColumn('modelo_user_accesses.modelo_id', 'modelos.id')
                            ->where('modelo_user_accesses.user_id', $userId);
                    });
                }
            });

        return $query->exists();
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function hasPlatformOrderIdColumn(): bool
    {
        return $this->hasColumn('platform_order_id');
    }

    private function hasColumn(string $column): bool
    {
        static $columns = [];
        if (!array_key_exists($column, $columns)) {
            $columns[$column] = Schema::hasColumn('manual_print_orders', $column);
        }

        return $columns[$column];
    }

    private function platformOrderExists(int $userId, string $platformOrderId): bool
    {
        return DB::table('manual_print_orders')
            ->where('user_id', $userId)
            ->where('platform_order_id', $platformOrderId)
            ->exists();
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'platformOrderId' => isset($row->platform_order_id) && $row->platform_order_id ? (string) $row->platform_order_id : null,
            'isGroupOrder' => isset($row->is_group_order) ? (bool) $row->is_group_order : false,
            'groupSize' => isset($row->group_size) && $row->group_size ? (int) $row->group_size : null,
            'modelId' => $row->modelo_id ? (string) $row->modelo_id : null,
            'values' => $row->values ? json_decode($row->values, true) : [],
            'quantity' => (int) ($row->quantity ?? 1),
            'status' => $row->status ?: 'Dados Pendente',
            'savedAt' => $row->saved_at,
            'printedAt' => $row->printed_at,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
