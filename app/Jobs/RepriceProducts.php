<?php

namespace App\Jobs;

use App\Models\MercadoLivreProduct;
use App\Models\PriceHistory;
use App\Services\MercadoLivreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RepriceProducts implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(MercadoLivreService $mlService): void
    {
        MercadoLivreProduct::query()
            ->where('auto_reprice', true)
            ->select([
                'id',
                'user_id',
                'item_id',
                'current_price',
                'cost_price',
                'min_margin',
                'shipping_cost',
                'taxes',
                'auto_reprice',
            ])
            ->with(['user.mercadoLivreAccount'])
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($mlService): void {
                foreach ($products as $product) {
                    try {
                        $accessToken = $product->user?->mercadoLivreAccount?->access_token;
                        if (!$accessToken) {
                            continue;
                        }

                        $competitors = $mlService->fetchCompetitorPrices($product->item_id, $accessToken);
                        $newPrice = $mlService->calculateReprice($product, $competitors);

                        if ($newPrice && $newPrice != $product->current_price) {
                            if ($mlService->updateItemPrice($product->item_id, $newPrice, $accessToken)) {
                                PriceHistory::create([
                                    'mercado_livre_product_id' => $product->id,
                                    'old_price' => $product->current_price,
                                    'new_price' => $newPrice,
                                    'reason' => 'repricing',
                                    'details' => ['competitors_count' => count($competitors)],
                                ]);

                                $product->update(['current_price' => $newPrice]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Erro no repricing: ' . $e->getMessage(), ['product_id' => $product->id]);
                    }
                }
            });
    }
}
