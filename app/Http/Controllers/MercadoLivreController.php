<?php

namespace App\Http\Controllers;

use App\Models\MercadoLivreProduct;
use App\Models\PriceHistory;
use App\Services\MercadoLivreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MercadoLivreController extends Controller
{
    public function dashboard()
    {
        $user = Auth::user();

        $productsQuery = MercadoLivreProduct::query()
            ->where('user_id', $user->id);

        $totalProducts = (clone $productsQuery)->count();
        $autoRepriceCount = (clone $productsQuery)
            ->where('auto_reprice', true)
            ->count();

        $products = (clone $productsQuery)
            ->select([
                'id',
                'user_id',
                'title',
                'current_price',
                'cost_price',
                'min_margin',
                'shipping_cost',
                'taxes',
                'auto_reprice',
                'updated_at',
            ])
            ->orderByDesc('updated_at')
            ->simplePaginate(25);

        $recentChanges = PriceHistory::query()
            ->select([
                'id',
                'mercado_livre_product_id',
                'old_price',
                'new_price',
                'reason',
                'created_at',
            ])
            ->join('mercado_livre_products', 'mercado_livre_products.id', '=', 'price_histories.mercado_livre_product_id')
            ->with('mercadoLivreProduct:id,user_id,title')
            ->where('mercado_livre_products.user_id', $user->id)
            ->latest('price_histories.created_at')
            ->take(10)
            ->get();

        return view('mercado-livre.dashboard', compact('products', 'totalProducts', 'autoRepriceCount', 'recentChanges'));
    }

    public function betaDashboard()
    {
        return view('mercado-livre.beta-dashboard');
    }

    public function storeProduct(Request $request, MercadoLivreService $mlService)
    {
        $request->validate([
            'item_id' => 'required|string|unique:mercado_livre_products',
            'title' => 'required|string',
            'current_price' => 'required|numeric',
            'cost_price' => 'nullable|numeric',
            'min_margin' => 'nullable|numeric|min:0|max:100',
        ]);

        $user = Auth::user();
        $accessToken = $user->mercadoLivreAccount?->access_token;

        if ($accessToken) {
            $competitors = $mlService->fetchCompetitorPrices($request->item_id, $accessToken);
        } else {
            $competitors = [];
        }

        MercadoLivreProduct::create([
            'user_id' => $user->id,
            'item_id' => $request->item_id,
            'title' => $request->title,
            'current_price' => $request->current_price,
            'cost_price' => $request->cost_price,
            'min_margin' => $request->min_margin ?? 10,
            'competitors' => $competitors,
        ]);

        return redirect()->back()->with('success', 'Produto adicionado com sucesso!');
    }

    public function updatePrice(Request $request, $id)
    {
        $product = MercadoLivreProduct::findOrFail($id);
        $this->authorize('update', $product);

        $request->validate([
            'new_price' => 'required|numeric|min:0',
        ]);

        $oldPrice = $product->current_price;
        $product->update(['current_price' => $request->new_price]);

        PriceHistory::create([
            'mercado_livre_product_id' => $product->id,
            'old_price' => $oldPrice,
            'new_price' => $request->new_price,
            'reason' => 'manual',
        ]);

        return redirect()->back()->with('success', 'Preço atualizado!');
    }
}
