<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ShopeeOrderController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (!$this->isAdmin($user->role ?? null)) {
            return response()->json(['message' => 'Acesso permitido apenas para admin.'], 403);
        }

        $year = max(0, (int) $request->query('year', 0));
        $month = max(0, min(12, (int) $request->query('month', 0)));
        $query = DB::table('shopee_order_reports')
            ->where('user_id', $user->id)
            ->whereNotNull('product_name')
            ->whereRaw("trim(product_name) <> ''")
            ->whereRaw("trim(product_name) <> '-'");
        if ($year > 0) {
            $query->whereYear('order_created_at', $year);
        }
        if ($month > 0) {
            $query->whereMonth('order_created_at', $month);
        }

        $summary = (clone $query)
            ->selectRaw('count(*) as total_rows')
            ->selectRaw('coalesce(sum(case when revenue_amount > 0 then revenue_amount else 0 end), 0) as received_total')
            ->selectRaw('coalesce(sum(case when revenue_amount <= 0 then revenue_amount else 0 end), 0) as unpaid_total')
            ->selectRaw('count(distinct product_name) as product_total')
            ->first();

        $rows = (clone $query)
            ->orderByDesc('order_created_at')
            ->orderByDesc('id')
            ->get();

        $productMap = $this->loadProductMap($user->id, $rows);
        $products = $this->loadAllProducts($user->id);

        $mappedRows = $rows
            ->map(function ($row) use ($productMap) {
                $product = $productMap[$this->normalizeProductName((string) ($row->product_name ?? ''))] ?? null;
                $cost = (float) ($product['production_cost'] ?? 0);
                $revenue = (float) $row->revenue_amount;

                return [
                    'id' => (string) $row->id,
                    'import_key' => $row->import_key,
                    'sequence_number' => $row->sequence_number ? (int) $row->sequence_number : null,
                    'order_id' => $row->order_id,
                    'refund_id' => $row->refund_id,
                    'sku' => $row->sku,
                    'product_name' => $row->product_name,
                    'order_created_at' => $row->order_created_at,
                    'payment_completed_at' => $row->payment_completed_at,
                    'release_channel' => $row->release_channel,
                    'order_type' => $row->order_type,
                    'hot_listing' => $row->hot_listing,
                    'revenue_amount' => $revenue,
                    'product_price' => (float) $row->product_price,
                    'source_file_name' => $row->source_file_name,
                    'row_raw' => $this->decodeJson($row->row_raw),
                    'financial_status' => $revenue > 0 ? 'received' : 'unpaid',
                    'linked_product' => $product,
                    'estimated_net_profit' => $product ? round($revenue - $cost, 2) : null,
                ];
            })
            ->values();

        $profitTotal = round(
            $mappedRows->reduce(function (float $carry, array $row) {
                return $carry + (float) ($row['estimated_net_profit'] ?? 0);
            }, 0),
            2
        );

        $filters = DB::table('shopee_order_reports')
            ->where('user_id', $user->id)
            ->whereNotNull('product_name')
            ->whereRaw("trim(product_name) <> ''")
            ->whereRaw("trim(product_name) <> '-'")
            ->whereNotNull('order_created_at')
            ->orderByDesc('order_created_at')
            ->get(['order_created_at'])
            ->reduce(function (Collection $carry, object $row) {
                $date = (string) ($row->order_created_at ?? '');
                if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $matches)) {
                    return $carry;
                }

                $key = $matches[1] . '-' . $matches[2];
                $item = $carry->get($key, [
                    'year' => (int) $matches[1],
                    'month' => (int) $matches[2],
                    'total' => 0,
                ]);
                $item['total']++;
                $carry->put($key, $item);

                return $carry;
            }, collect())
            ->sortByDesc(fn (array $item) => sprintf('%04d-%02d', $item['year'], $item['month']))
            ->values();

        return response()->json([
            'summary' => [
                'total_rows' => (int) ($summary->total_rows ?? 0),
                'received_total' => (float) ($summary->received_total ?? 0),
                'unpaid_total' => (float) ($summary->unpaid_total ?? 0),
                'product_total' => (int) ($summary->product_total ?? 0),
                'profit_total' => $profitTotal,
            ],
            'rows' => $mappedRows,
            'filters' => $filters,
            'products' => array_values($products),
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (!$this->isAdmin($user->role ?? null)) {
            return response()->json(['message' => 'Acesso permitido apenas para admin.'], 403);
        }

        $rows = $request->input('rows', []);
        if (!is_array($rows) || $rows === []) {
            return response()->json(['message' => 'Envie um array `rows` para importar.'], 422);
        }

        $existingProducts = DB::table('shopee_products')
            ->where('user_id', $user->id)
            ->get(['id', 'product_name', 'original_price', 'production_cost', 'materials_json']);

        $productMap = [];
        foreach ($existingProducts as $product) {
            $productMap[$this->normalizeProductName((string) $product->product_name)] = $product;
        }

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $productsCreated = 0;
        $productsUpdated = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productName = trim((string) ($row['product_name'] ?? ''));
            if ($productName === '' || $productName === '-') {
                continue;
            }

            $validator = Validator::make($row, [
                'import_key' => ['required', 'string', 'max:190'],
                'sequence_number' => ['nullable', 'integer', 'min:1'],
                'order_id' => ['nullable', 'string', 'max:120'],
                'refund_id' => ['nullable', 'string', 'max:120'],
                'sku' => ['nullable', 'string', 'max:190'],
                'product_name' => ['nullable', 'string', 'max:255'],
                'order_created_at' => ['nullable', 'date'],
                'payment_completed_at' => ['nullable', 'date'],
                'release_channel' => ['nullable', 'string', 'max:120'],
                'order_type' => ['nullable', 'string', 'max:120'],
                'hot_listing' => ['nullable', 'string', 'max:30'],
                'revenue_amount' => ['nullable', 'numeric'],
                'product_price' => ['nullable', 'numeric'],
                'source_file_name' => ['nullable', 'string', 'max:190'],
                'row_raw' => ['nullable', 'array'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Dados invalidos na importacao da Shopee.',
                    'errors' => $validator->errors(),
                    'row' => $row,
                ], 422);
            }

            $payload = [
                'sequence_number' => $row['sequence_number'] ?? null,
                'order_id' => $row['order_id'] ?? null,
                'refund_id' => $row['refund_id'] ?? null,
                'sku' => $row['sku'] ?? null,
                'product_name' => $row['product_name'] ?? null,
                'order_created_at' => $row['order_created_at'] ?? null,
                'payment_completed_at' => $row['payment_completed_at'] ?? null,
                'release_channel' => $row['release_channel'] ?? null,
                'order_type' => $row['order_type'] ?? null,
                'hot_listing' => $row['hot_listing'] ?? null,
                'revenue_amount' => round((float) ($row['revenue_amount'] ?? 0), 2),
                'product_price' => round((float) ($row['product_price'] ?? 0), 2),
                'source_file_name' => $row['source_file_name'] ?? null,
                'row_raw' => isset($row['row_raw']) ? json_encode($row['row_raw']) : null,
            ];

            $existing = DB::table('shopee_order_reports')
                ->where('user_id', $user->id)
                ->where('import_key', $row['import_key'])
                ->first();

            if (!$existing) {
                DB::table('shopee_order_reports')->insert(array_merge($payload, [
                    'user_id' => $user->id,
                    'import_key' => $row['import_key'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $inserted++;
            } else {
                $changes = [];
                foreach ($payload as $field => $value) {
                    $currentValue = $field === 'row_raw'
                        ? json_encode($this->decodeJson($existing->{$field}))
                        : $existing->{$field};

                    if ((string) ($currentValue ?? '') !== (string) ($value ?? '')) {
                        $changes[$field] = $value;
                    }
                }

                if ($changes === []) {
                    $unchanged++;
                } else {
                    $changes['updated_at'] = now();
                    DB::table('shopee_order_reports')->where('id', $existing->id)->update($changes);
                    $updated++;
                }
            }

            $normalizedName = $this->normalizeProductName($productName);
            $productPrice = round((float) ($row['product_price'] ?? 0), 2);
            $positiveProductPrice = max(0, $productPrice);

            if ($normalizedName === '' || $productName === '') {
                continue;
            }

            if (!isset($productMap[$normalizedName])) {
                $productId = DB::table('shopee_products')->insertGetId([
                    'user_id' => $user->id,
                    'product_name' => $productName,
                    'original_price' => $positiveProductPrice,
                    'production_cost' => 0,
                    'materials_json' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $productMap[$normalizedName] = (object) [
                    'id' => $productId,
                    'product_name' => $productName,
                    'original_price' => $positiveProductPrice,
                    'production_cost' => 0,
                    'materials_json' => null,
                ];
                $productsCreated++;
                continue;
            }

            $existingProduct = $productMap[$normalizedName];
            if ((float) ($existingProduct->original_price ?? 0) <= 0 && $positiveProductPrice > 0) {
                DB::table('shopee_products')
                    ->where('id', $existingProduct->id)
                    ->update([
                        'original_price' => $positiveProductPrice,
                        'updated_at' => now(),
                    ]);

                $existingProduct->original_price = $positiveProductPrice;
                $productMap[$normalizedName] = $existingProduct;
                $productsUpdated++;
            }
        }

        return response()->json([
            'message' => 'Importacao Shopee concluida.',
            'stats' => [
                'inserted' => $inserted,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'products_created' => $productsCreated,
                'products_updated' => $productsUpdated,
            ],
        ]);
    }

    public function updateProduct(Request $request, string $product): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (!$this->isAdmin($user->role ?? null)) {
            return response()->json(['message' => 'Acesso permitido apenas para admin.'], 403);
        }

        $row = DB::table('shopee_products')
            ->where('id', (int) $product)
            ->where('user_id', $user->id)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Produto Shopee nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'original_price' => ['sometimes', 'numeric', 'min:0'],
            'production_cost' => ['sometimes', 'numeric', 'min:0'],
            'materials_json' => ['sometimes', 'nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados invalidos para produto Shopee.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::table('shopee_products')
            ->where('id', (int) $product)
            ->update([
                'original_price' => $request->has('original_price') ? round((float) $request->input('original_price'), 2) : (float) $row->original_price,
                'production_cost' => $request->has('production_cost') ? round((float) $request->input('production_cost'), 2) : (float) $row->production_cost,
                'materials_json' => $request->has('materials_json')
                    ? json_encode($request->input('materials_json'))
                    : $row->materials_json,
                'updated_at' => now(),
            ]);

        $updated = DB::table('shopee_products')->where('id', (int) $product)->first();

        return response()->json([
            'message' => 'Produto Shopee atualizado com sucesso.',
            'product' => $updated ? $this->mapShopeeProduct($updated) : null,
        ]);
    }

    public function destroyYear(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (!$this->isAdmin($user->role ?? null)) {
            return response()->json(['message' => 'Acesso permitido apenas para admin.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Informe um ano valido para excluir os pedidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $year = (int) $request->input('year');

        $deleted = DB::table('shopee_order_reports')
            ->where('user_id', $user->id)
            ->whereYear('order_created_at', $year)
            ->delete();

        return response()->json([
            'message' => "Pedidos Shopee do ano {$year} excluidos com sucesso.",
            'deleted' => $deleted,
            'year' => $year,
        ]);
    }

    public function destroyProducts(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (!$this->isAdmin($user->role ?? null)) {
            return response()->json(['message' => 'Acesso permitido apenas para admin.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Selecione ao menos um produto para excluir.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ids = collect($request->input('ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            return response()->json(['message' => 'Selecione produtos validos para excluir.'], 422);
        }

        $deleted = DB::table('shopee_products')
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json([
            'message' => "{$deleted} produto(s) Shopee excluido(s) com sucesso.",
            'deleted' => $deleted,
        ]);
    }

    private function loadProductMap(int $userId, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        return DB::table('shopee_products')
            ->where('user_id', $userId)
            ->get()
            ->mapWithKeys(function ($row) {
                $normalized = $this->normalizeProductName((string) $row->product_name);
                return [$normalized => $this->mapShopeeProduct($row)];
            })
            ->all();
    }

    private function loadAllProducts(int $userId): array
    {
        return DB::table('shopee_products')
            ->where('user_id', $userId)
            ->orderBy('product_name')
            ->get()
            ->map(fn ($row) => $this->mapShopeeProduct($row))
            ->values()
            ->all();
    }

    private function mapShopeeProduct(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'product_name' => $row->product_name,
            'original_price' => (float) $row->original_price,
            'production_cost' => (float) ($row->production_cost ?? 0),
            'materials_json' => $this->decodeJson($row->materials_json),
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function normalizeProductName(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return mb_strtolower($normalized, 'UTF-8');
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return $value;
    }

    private function isAdmin(?string $role): bool
    {
        return mb_strtolower((string) $role, 'UTF-8') === 'admin';
    }
}
