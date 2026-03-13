<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ShippingOrderController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = DB::table('shipping_orders')->where('user_id', $user->id)->orderByDesc('updated_at');

        $tracking = trim((string) $request->query('tracking', ''));
        if ($tracking !== '') {
            $query->where('tracking_number', 'like', '%' . $tracking . '%');
        }

        $shippingDate = trim((string) $request->query('shipping_date', ''));
        if ($shippingDate !== '') {
            if ($shippingDate === 'sem-data') {
                $query->whereNull('shipping_deadline');
            } else {
                $query->whereDate('shipping_deadline', $shippingDate);
            }
        }

        return response()->json([
            'orders' => $query->limit((int) $request->input('limit', 2000))->get()->map(fn ($row) => $this->mapRow($row))->values(),
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $rows = $request->input('rows', []);
        if (!is_array($rows) || $rows === []) {
            return response()->json(['message' => 'Envie um array `rows` para importar.'], 422);
        }

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $validator = Validator::make($row, [
                'import_key' => ['required', 'string', 'max:190'],
                'platform_order_number' => ['nullable', 'string', 'max:120'],
                'ad_name' => ['nullable', 'string', 'max:255'],
                'variation' => ['nullable', 'string', 'max:255'],
                'image_url' => ['nullable', 'string'],
                'buyer_notes' => ['nullable', 'string'],
                'observations' => ['nullable', 'string'],
                'product_qty' => ['nullable', 'integer', 'min:1'],
                'recipient_name' => ['nullable', 'string', 'max:190'],
                'tracking_number' => ['nullable', 'string', 'max:120'],
                'source_file_name' => ['nullable', 'string', 'max:190'],
                'shipping_deadline' => ['nullable', 'date'],
                'packed' => ['nullable', 'boolean'],
                'production_separated' => ['nullable', 'boolean'],
                'row_raw' => ['nullable', 'array'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Dados invalidos na importacao de pedidos.',
                    'errors' => $validator->errors(),
                    'row' => $row,
                ], 422);
            }

            $existing = DB::table('shipping_orders')
                ->where('user_id', $user->id)
                ->where('import_key', $row['import_key'])
                ->first();

            if (!$existing) {
                DB::table('shipping_orders')->insert([
                    'user_id' => $user->id,
                    'import_key' => $row['import_key'],
                    'platform_order_number' => $row['platform_order_number'] ?? null,
                    'ad_name' => $row['ad_name'] ?? 'Produto sem titulo',
                    'variation' => $row['variation'] ?? null,
                    'image_url' => $row['image_url'] ?? null,
                    'buyer_notes' => $row['buyer_notes'] ?? null,
                    'observations' => $row['observations'] ?? null,
                    'product_qty' => (int) ($row['product_qty'] ?? 1),
                    'recipient_name' => $row['recipient_name'] ?? null,
                    'tracking_number' => $row['tracking_number'] ?? null,
                    'source_file_name' => $row['source_file_name'] ?? null,
                    'shipping_deadline' => $row['shipping_deadline'] ?? $this->shippingDeadlineFromRaw($row['row_raw'] ?? null),
                    'packed' => (bool) ($row['packed'] ?? false),
                    'production_separated' => (bool) ($row['production_separated'] ?? false),
                    'row_raw' => isset($row['row_raw']) ? json_encode($row['row_raw']) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
                continue;
            }

            $payload = [];
            $incomingRaw = is_array($row['row_raw'] ?? null) ? $row['row_raw'] : [];
            $currentRaw = $this->decodeJson($existing->row_raw) ?: [];
            $mergedRaw = $currentRaw;

            foreach ($incomingRaw as $key => $value) {
                if ((!array_key_exists($key, $mergedRaw) || $this->isBlank($mergedRaw[$key])) && !$this->isBlank($value)) {
                    $mergedRaw[$key] = $value;
                }
            }

            foreach ([
                'platform_order_number',
                'ad_name',
                'variation',
                'image_url',
                'buyer_notes',
                'observations',
                'recipient_name',
                'tracking_number',
                'source_file_name',
            ] as $field) {
                $current = $existing->{$field};
                $incoming = $row[$field] ?? null;
                if ($this->isBlank($current) && !$this->isBlank($incoming)) {
                    $payload[$field] = $incoming;
                }
            }

            if (((int) $existing->product_qty) <= 0 && (int) ($row['product_qty'] ?? 0) > 0) {
                $payload['product_qty'] = (int) $row['product_qty'];
            }

            $incomingShippingDeadline = $row['shipping_deadline'] ?? $this->shippingDeadlineFromRaw($incomingRaw);
            if (!$existing->shipping_deadline && $incomingShippingDeadline) {
                $payload['shipping_deadline'] = $incomingShippingDeadline;
            }

            if ($mergedRaw !== $currentRaw) {
                $payload['row_raw'] = json_encode($mergedRaw);
            }

            if ($payload === []) {
                $unchanged++;
                continue;
            }

            $payload['updated_at'] = now();
            DB::table('shipping_orders')->where('id', $existing->id)->update($payload);
            $updated++;
        }

        return response()->json([
            'message' => 'Importacao concluida.',
            'stats' => [
                'inserted' => $inserted,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ],
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $term = trim((string) ($request->query('q') ?: $request->query('tracking') ?: $request->query('order')));
        if ($term === '') {
            return response()->json(['message' => 'Informe um termo de busca em `q`, `tracking` ou `order`.'], 422);
        }

        $prefixTerm = $term . '%';
        $likeTerm = '%' . $term . '%';

        $rows = DB::table('shipping_orders')
            ->where('user_id', $user->id)
            ->where(function ($builder) use ($term) {
                $builder
                    ->where('tracking_number', $term)
                    ->orWhere('platform_order_number', $term);
            })
            ->orWhere(function ($builder) use ($user, $prefixTerm, $likeTerm) {
                $builder
                    ->where('user_id', $user->id)
                    ->where(function ($inner) use ($prefixTerm, $likeTerm) {
                        $inner
                            ->where('tracking_number', 'like', $prefixTerm)
                            ->orWhere('platform_order_number', 'like', $prefixTerm)
                            ->orWhere('tracking_number', 'like', $likeTerm)
                            ->orWhere('platform_order_number', 'like', $likeTerm)
                            ->orWhere('recipient_name', 'like', $likeTerm);
                    });
            })
            ->orderByRaw(
                "case
                    when tracking_number = ? then 0
                    when platform_order_number = ? then 1
                    when tracking_number like ? then 2
                    when platform_order_number like ? then 3
                    else 4
                end",
                [$term, $term, $prefixTerm, $prefixTerm]
            )
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->values();

        return response()->json(['orders' => $rows]);
    }

    public function destroyByDate(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $date = trim((string) $request->input('shipping_date', $request->input('date', '')));
        if ($date === '') {
            return response()->json(['message' => 'Informe `shipping_date` para excluir a lista.'], 422);
        }

        $query = DB::table('shipping_orders')->where('user_id', $user->id);
        if ($date === 'sem-data') {
            $query->whereNull('shipping_deadline');
        } else {
            $query->whereDate('shipping_deadline', $date);
        }

        $deleted = $query->delete();

        return response()->json([
            'message' => 'Lista excluida com sucesso.',
            'deleted' => $deleted,
            'shipping_date' => $date,
        ]);
    }

    public function update(Request $request, string $order): JsonResponse
    {
        $row = $this->findOwnedRow($request, $order);
        if (!$row) {
            return response()->json(['message' => 'Pedido nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'platform_order_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ad_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'variation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'image_url' => ['sometimes', 'nullable', 'string'],
            'buyer_notes' => ['sometimes', 'nullable', 'string'],
            'observations' => ['sometimes', 'nullable', 'string'],
            'product_qty' => ['sometimes', 'integer', 'min:1'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'source_file_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'shipping_deadline' => ['sometimes', 'nullable', 'date'],
            'packed' => ['sometimes', 'boolean'],
            'production_separated' => ['sometimes', 'boolean'],
            'row_raw' => ['sometimes', 'nullable', 'array'],
            'merge_row_raw' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para pedido.', 'errors' => $validator->errors()], 422);
        }

        $payload = [];
        foreach ([
            'platform_order_number',
            'ad_name',
            'variation',
            'image_url',
            'buyer_notes',
            'observations',
            'product_qty',
            'recipient_name',
            'tracking_number',
            'source_file_name',
            'shipping_deadline',
            'packed',
            'production_separated',
        ] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        if ($request->has('row_raw')) {
            $currentRaw = $this->decodeJson($row->row_raw) ?: [];
            $nextRaw = $request->boolean('merge_row_raw', true)
                ? array_replace($currentRaw, (array) $request->input('row_raw', []))
                : (array) $request->input('row_raw', []);

            $payload['row_raw'] = $nextRaw;
            $payload['row_raw'] = json_encode($nextRaw);
            if (!$request->has('shipping_deadline')) {
                $payload['shipping_deadline'] = $this->shippingDeadlineFromRaw($nextRaw) ?: $row->shipping_deadline;
            }
            if (!$request->has('packed') && array_key_exists('packed', $nextRaw)) {
                $payload['packed'] = (bool) $nextRaw['packed'];
            }
            if (!$request->has('production_separated') && array_key_exists('production_separated', $nextRaw)) {
                $payload['production_separated'] = (bool) $nextRaw['production_separated'];
            }
        }

        $payload['updated_at'] = now();
        DB::table('shipping_orders')->where('id', (int) $order)->update($payload);
        $updated = DB::table('shipping_orders')->where('id', (int) $order)->first();

        return response()->json([
            'message' => 'Pedido atualizado com sucesso.',
            'order' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $order): JsonResponse
    {
        $row = $this->findOwnedRow($request, $order);
        if (!$row) {
            return response()->json(['message' => 'Pedido nao encontrado.'], 404);
        }

        DB::table('shipping_orders')->where('id', (int) $order)->delete();

        return response()->json(['message' => 'Pedido excluido com sucesso.', 'id' => $order]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $ids = $request->input('ids', []);
        $importKeys = $request->input('import_keys', []);

        $query = DB::table('shipping_orders')->where('user_id', $user->id);
        if (is_array($ids) && $ids !== []) {
            $query->whereIn('id', array_map('intval', $ids));
        } elseif (is_array($importKeys) && $importKeys !== []) {
            $query->whereIn('import_key', $importKeys);
        } else {
            return response()->json(['message' => 'Envie `ids` ou `import_keys` para exclusao em lote.'], 422);
        }

        $deleted = $query->delete();

        return response()->json(['message' => 'Pedidos excluidos com sucesso.', 'deleted' => $deleted]);
    }

    private function findOwnedRow(Request $request, string $order): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('shipping_orders')->where('id', (int) $order)->where('user_id', $user->id)->first();
    }

    private function mapRow(object $row): array
    {
        $raw = $this->decodeJson($row->row_raw) ?: [];
        if (!array_key_exists('packed', $raw)) {
            $raw['packed'] = (bool) $row->packed;
        }
        if (!array_key_exists('production_separated', $raw)) {
            $raw['production_separated'] = (bool) $row->production_separated;
        }
        if (!array_key_exists('shipping_deadline', $raw) && $row->shipping_deadline) {
            $raw['shipping_deadline'] = $row->shipping_deadline;
        }

        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'import_key' => $row->import_key,
            'platform_order_number' => $row->platform_order_number,
            'ad_name' => $row->ad_name,
            'variation' => $row->variation,
            'image_url' => $row->image_url,
            'buyer_notes' => $row->buyer_notes,
            'observations' => $row->observations,
            'product_qty' => (int) ($row->product_qty ?? 1),
            'recipient_name' => $row->recipient_name,
            'tracking_number' => $row->tracking_number,
            'source_file_name' => $row->source_file_name,
            'shipping_deadline' => $row->shipping_deadline,
            'packed' => (bool) $row->packed,
            'production_separated' => (bool) $row->production_separated,
            'row_raw' => $raw,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }

    private function shippingDeadlineFromRaw(mixed $raw): ?string
    {
        $data = is_array($raw) ? $raw : $this->decodeJson($raw);
        if (!$data) {
            return null;
        }

        foreach (['shipping_deadline', 'prazo_de_envio', 'prazo_envio', 'data_de_envio', 'data_envio'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            return substr(trim($value), 0, 10);
        }

        return null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || (is_string($value) && trim($value) === '');
    }
}
