<?php

namespace App\Services;

use App\Models\GpCategory;
use App\Models\GpProduct;
use App\Models\MlSaleProduct;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class MercadoLivreSaleProductService
{
    /** Importacao local idempotente: nunca escreve na API do Mercado Livre. */
    public function importOrders(User $user, int $sellerId, array $orders): array
    {
        $candidates = [];
        foreach ($orders as $order) {
            if (!is_array($order) || !in_array($order['status'] ?? '', ['paid', 'partially_paid'], true)) {
                continue;
            }
            if (isset($order['seller']['id']) && (int) $order['seller']['id'] !== $sellerId) {
                continue;
            }
            try {
                $date = is_string($order['date_created'] ?? null) && $order['date_created'] !== '' ? Carbon::parse($order['date_created'])->utc() : null;
            } catch (\Exception) {
                $date = null;
            }
            foreach (is_array($order['order_items'] ?? null) ? $order['order_items'] : [] as $row) {
                if (!is_array($row) || !is_array($row['item'] ?? null)) continue;
                $item = $row['item'] ?? [];
                $itemId = trim((string) ($item['id'] ?? ''));
                if (!preg_match('/^ML[A-Z][0-9]+$/', $itemId)) {
                    continue;
                }
                $variationId = (string) (($item['variation_id'] ?? null) ?: '');
                $key = $itemId . ':' . $variationId;
                if (!isset($candidates[$key]) || ($date?->timestamp ?? 0) > ($candidates[$key]['date']?->timestamp ?? 0)) {
                    $candidates[$key] = ['item' => $item, 'row' => $row, 'order_id' => $order['id'] ?? null, 'date' => $date];
                }
            }
        }

        $created = 0;
        if ($candidates !== []) {
            DB::transaction(function () use ($user, $sellerId, $candidates, &$created) {
                // Serializa importacoes simultaneas do painel/bot para esta conta.
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $category = null;
                foreach ($candidates as $candidate) {
                    $item = $candidate['item'];
                    $record = MlSaleProduct::firstOrNew([
                        'user_id' => $user->id, 'seller_id' => $sellerId,
                        'item_id' => (string) $item['id'], 'variation_id' => (string) (($item['variation_id'] ?? null) ?: ''),
                    ]);
                    $new = !$record->exists;
                    $title = mb_substr(trim((string) ($item['title'] ?? '')) ?: $item['id'], 0, 255);
                    $price = isset($candidate['row']['unit_price']) ? max(0, (float) $candidate['row']['unit_price']) : null;
                    $thumb = $this->imageUrl((string) ($item['thumbnail'] ?? ''));
                    if ($new) {
                        $record->title = $title;
                        $record->sku = mb_substr((string) ($item['seller_sku'] ?? ''), 0, 120) ?: null;
                        $record->thumbnail = $thumb;
                        $record->pictures = $thumb ? [$thumb] : [];
                        $record->listing_price = $price;
                        $created++;
                    } elseif (!$record->thumbnail && $thumb) {
                        $record->thumbnail = $thumb;
                    }
                    $date = $candidate['date'];
                    if ($record->last_sale_at === null || ($date !== null && $date->greaterThanOrEqualTo($record->last_sale_at))) {
                        $record->last_sale_at = $date;
                        $record->last_sale_price = $price;
                        $record->last_order_id = $candidate['order_id'];
                    }
                    if (!$record->gp_product_id) {
                        $category ??= GpCategory::firstOrCreate(['user_id' => $user->id, 'name' => 'Mercado Livre']);
                        $product = GpProduct::create([
                            'user_id' => $user->id, 'category_id' => $category->id,
                            'name' => $title, 'sku' => mb_substr((string) ($item['seller_sku'] ?? ''), 0, 100) ?: null,
                            'sell_price' => $price ?? 0, 'image_url' => $thumb, 'unit' => 'un',
                            'pricing_type' => 'fixed', 'stock_qty' => 0, 'active' => false,
                            'cost_materials' => 0, 'cost_labor' => 0, 'cost_fixed' => 0, 'cost_other' => 0,
                        ]);
                        $record->gp_product_id = $product->id;
                        $record->costs_configured_at = null;
                    }
                    // Custos, materiais, taxas e preco planejado nunca sao sobrescritos aqui.
                    if (!$record->exists || $record->isDirty()) $record->save();
                }
            });
        }

        return ['created' => $created, 'recognized' => count($candidates)];
    }

    public function refreshDetails(MlSaleProduct $record, array $item): MlSaleProduct
    {
        $pictures = [];
        foreach ($item['pictures'] ?? [] as $picture) {
            $url = $this->imageUrl((string) ($picture['secure_url'] ?? $picture['url'] ?? ''));
            if ($url) $pictures[] = $url;
        }
        // As fotos de uma variacao pertencem a ela, nao necessariamente a primeira foto do anuncio.
        foreach ($item['variations'] ?? [] as $variation) {
            if ((string) ($variation['id'] ?? '') !== $record->variation_id) continue;
            $ids = $variation['picture_ids'] ?? [];
            if ($ids !== []) {
                $pictures = [];
                foreach ($item['pictures'] ?? [] as $picture) {
                    if (in_array($picture['id'] ?? '', $ids, true)) {
                        $url = $this->imageUrl((string) ($picture['secure_url'] ?? $picture['url'] ?? ''));
                        if ($url) $pictures[] = $url;
                    }
                }
            }
            if (isset($variation['price'])) $item['price'] = $variation['price'];
        }
        $thumb = $pictures[0] ?? $this->imageUrl((string) ($item['secure_thumbnail'] ?? $item['thumbnail'] ?? ''));
        $record->fill([
            'title' => mb_substr((string) ($item['title'] ?? $record->title), 0, 255),
            'thumbnail' => $thumb ?: $record->thumbnail,
            'pictures' => $pictures ?: $record->pictures,
            'description' => (string) ($item['description'] ?? ''),
            'listing_price' => $item['price'] ?? $record->listing_price,
            'permalink' => $item['permalink'] ?? $record->permalink,
            'details_synced_at' => now(),
        ])->save();
        // Completa dados importados inicialmente, mas preserva dados editados pelo usuario.
        GpProduct::where('user_id', $record->user_id)->whereKey($record->gp_product_id)->whereNull('description')
            ->update(['description' => mb_strcut($record->description, 0, 60000, 'UTF-8')]);
        GpProduct::where('user_id', $record->user_id)->whereKey($record->gp_product_id)->whereNull('image_url')
            ->update(['image_url' => $record->thumbnail]);

        return $record;
    }

    public function attachCosts(User $user, int $sellerId, array $orders): array
    {
        $ids = collect($orders)->flatMap(fn ($order) => collect($order['order_items'] ?? [])->pluck('item.id'))->filter()->unique();
        $products = MlSaleProduct::where('user_id', $user->id)->where('seller_id', $sellerId)
            ->whereIn('item_id', $ids)->with('product.materials')->get()
            ->keyBy(fn (MlSaleProduct $record) => $record->item_id . ':' . $record->variation_id);
        foreach ($orders as &$order) {
            foreach ($order['order_items'] ?? [] as $index => $row) {
                $record = $products->get(($row['item']['id'] ?? '') . ':' . (string) (($row['item']['variation_id'] ?? null) ?: ''));
                if (!$record || !$record->product || (int) $record->product->user_id !== (int) $user->id) continue;
                $order['order_items'][$index]['local_product'] = [
                    'id' => $record->gp_product_id, 'name' => $record->product->name,
                    'cost_configured' => $record->costs_configured_at !== null,
                    'unit_cost' => $record->costs_configured_at ? $record->product->productionCost() : null,
                ];
            }
        }
        unset($order);

        return $orders;
    }

    private function imageUrl(string $url): ?string
    {
        return preg_match('~^https?://~i', $url) ? preg_replace('~^http://~i', 'https://', $url) : null;
    }
}
