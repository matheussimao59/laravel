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
        $products = MercadoLivreProduct::where('auto_reprice', true)->get();

        foreach ($products as $product) {
            try {
                // Assumir que o usuário tem access_token armazenado (ex: em User model)
                $accessToken = $product->user->mercado_livre_access_token ?? null;
                if (!$accessToken) continue;

                $competitors = $mlService->fetchCompetitorPrices($product->item_id, $accessToken);
                $newPrice = $mlService->calculateReprice($product, $competitors);

                if ($newPrice && $newPrice != $product->current_price) {
                    if ($mlService->updateItemPrice($product->item_id, $newPrice, $accessToken)) {
                        // Salvar histórico
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
    }
}
