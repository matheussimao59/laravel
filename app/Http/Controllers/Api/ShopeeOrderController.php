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
        $limit = max(1, min(500, (int) $request->query('limit', 250)));

        $query = DB::table('shopee_order_reports')->where('user_id', $user->id);
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
            ->selectRaw('coalesce(sum(product_price), 0) as product_total')
            ->first();

        $rows = (clone $query)
            ->orderByDesc('order_created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $productMap = $this->loadProductMap($user->id, $rows);

        $mappedRows = $rows
            ->map(function ($row) use ($productMap) {
                $product = $productMap[$this->normalizeProductName((string) ($row->product_name ?? ''))] ?? null;
                $cost = (float) ($product['base_cost'] ?? 0);
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

        $filters = DB::table('shopee_order_reports')
            ->where('user_id', $user->id)
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
                'product_total' => (float) ($summary->product_total ?? 0),
            ],
            'rows' => $mappedRows,
            'filters' => $filters,
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

        $existingProducts = DB::table('pricing_products')
            ->where('user_id', $user->id)
            ->get(['id', 'product_name', 'selling_price']);

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
                'product_price' => ['nullable', 'numeric', 'min:0'],
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

            $normalizedName = $this->normalizeProductName((string) ($row['product_name'] ?? ''));
            $productName = trim((string) ($row['product_name'] ?? ''));
            $productPrice = round((float) ($row['product_price'] ?? 0), 2);

            if ($normalizedName === '' || $productName === '') {
                continue;
            }

            if (!isset($productMap[$normalizedName])) {
                $productId = DB::table('pricing_products')->insertGetId([
                    'user_id' => $user->id,
                    'product_name' => $productName,
                    'product_image_data' => null,
                    'selling_price' => $productPrice,
                    'base_cost' => 0,
                    'final_margin' => 0,
                    'materials_json' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $productMap[$normalizedName] = (object) [
                    'id' => $productId,
                    'product_name' => $productName,
                    'selling_price' => $productPrice,
                ];
                $productsCreated++;
                continue;
            }

            $existingProduct = $productMap[$normalizedName];
            if ((float) ($existingProduct->selling_price ?? 0) <= 0 && $productPrice > 0) {
                DB::table('pricing_products')
                    ->where('id', $existingProduct->id)
                    ->update([
                        'selling_price' => $productPrice,
                        'updated_at' => now(),
                    ]);

                $existingProduct->selling_price = $productPrice;
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

    private function loadProductMap(int $userId, Collection $rows): array
    {
        $names = $rows
            ->map(fn ($row) => trim((string) ($row->product_name ?? '')))
            ->filter()
            ->values()
            ->all();

        if ($names === []) {
            return [];
        }

        $products = DB::table('pricing_products')
            ->where('user_id', $userId)
            ->get()
            ->mapWithKeys(function ($row) {
                $normalized = $this->normalizeProductName((string) $row->product_name);
                return [$normalized => [
                    'id' => (string) $row->id,
                    'product_name' => $row->product_name,
                    'selling_price' => (float) $row->selling_price,
                    'base_cost' => (float) $row->base_cost,
                    'final_margin' => (float) $row->final_margin,
                    'materials_json' => $this->decodeJson($row->materials_json),
                ]];
            })
            ->all();

        return $products;
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
