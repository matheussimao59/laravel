<?php

namespace App\Http\Controllers\Api;

use App\Models\MlSaleProduct;
use App\Services\MercadoLivreSaleProductService;
use App\Services\MercadoLivreService;
use App\Support\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;

final class MercadoLivreSaleProductController
{
    public function __construct(private readonly MercadoLivreService $ml, private readonly MercadoLivreSaleProductService $catalog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $records = MlSaleProduct::where('user_id', $request->user()->id)
            ->with(['product.materials', 'product.discountTiers', 'product.cuttingMachine'])
            ->orderByDesc('last_sale_at')->get();

        return response()->json(['products' => $records->map(fn ($record) => $this->mapRecord($record))->filter()->values()]);
    }

    public function refresh(Request $request, int $id): JsonResponse
    {
        $record = MlSaleProduct::where('user_id', $request->user()->id)->find($id);
        if (!$record) return response()->json(['message' => 'Produto importado nao encontrado.'], 404);

        try {
            $token = $this->ml->accessTokenForUser($request->user());
            $seller = $this->ml->sellerForUser($request->user(), $token);
            if ((int) $record->seller_id !== (int) $seller['id']) {
                return response()->json(['message' => 'Este produto pertence a outra conta do Mercado Livre. Reconecte a conta correspondente para atualizar os dados.'], 422);
            }
            $item = $this->ml->saleItemDetailsForUser($request->user(), $record->item_id);
            if ((string) ($item['id'] ?? '') !== $record->item_id || (int) ($item['seller_id'] ?? 0) !== (int) $record->seller_id) {
                return response()->json(['message' => 'O anuncio retornado nao pertence a esta conta.'], 422);
            }
            $this->catalog->refreshDetails($record, $item);
            $record->refresh()->load(['product.materials', 'product.discountTiers', 'product.cuttingMachine']);

            return response()->json(['product' => $this->mapRecord($record), 'message' => 'Fotos e descricao atualizadas. Os custos locais foram preservados.']);
        } catch (ExternalServiceException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status() === 401 ? 422 : $exception->status());
        } catch (ConnectionException) {
            return response()->json(['message' => 'Falha ao consultar o anuncio no Mercado Livre. Os dados e custos locais foram preservados.'], 502);
        }
    }

    public function sync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Periodo de importacao invalido.', 'errors' => $validator->errors()], 422);

        try {
            $user = $request->user();
            $token = $this->ml->accessTokenForUser($user);
            $from = $request->filled('from_date') ? Carbon::parse($request->input('from_date')) : now()->subDays(30);
            if (strlen((string) $request->input('from_date')) === 10) $from->startOfDay();
            $to = $request->filled('to_date') ? Carbon::parse($request->input('to_date')) : null;
            if ($to && strlen((string) $request->input('to_date')) === 10) $to->endOfDay();
            $payload = $this->ml->syncOrders($token, $from->toIso8601String(),
                $to?->toIso8601String(), false, false, (int) $request->input('max_pages', 10), true, $this->ml->cachedSellerForUser($user));
            $this->ml->rememberSellerForUser($user, $payload['seller']);
            $summary = $this->catalog->importOrders($user, (int) $payload['seller']['id'], $payload['orders']);

            return response()->json(['message' => 'Produtos das vendas importados sem alterar os custos existentes.', 'summary' => $summary]);
        } catch (ExternalServiceException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status() === 401 ? 422 : $exception->status());
        } catch (ConnectionException) {
            return response()->json(['message' => 'Nao foi possivel consultar as vendas no Mercado Livre. Tente novamente mais tarde.'], 502);
        }
    }

    private function mapRecord(MlSaleProduct $record): ?array
    {
        $product = $record->product;
        if (!$product || (int) $product->user_id !== (int) $record->user_id) return null;

        return [
            ...$product->toArray(),
            'cost_materials' => $product->materials->isNotEmpty() ? $product->calculated_cost_materials : (float) $product->cost_materials,
            'ml_sale_product' => [
                'id' => $record->id, 'seller_id' => (string) $record->seller_id, 'item_id' => $record->item_id,
                'variation_id' => $record->variation_id, 'title' => $record->title, 'sku' => $record->sku,
                'thumbnail' => $record->thumbnail, 'pictures' => $record->pictures ?? [], 'description' => $record->description,
                'listing_price' => $record->listing_price, 'last_sale_price' => $record->last_sale_price,
                'last_sale_at' => $record->last_sale_at?->toIso8601String(),
                'details_synced_at' => $record->details_synced_at?->toIso8601String(),
                'costs_configured_at' => $record->costs_configured_at?->toIso8601String(),
                'permalink' => $record->permalink, 'production_cost' => $product->productionCost(),
            ],
        ];
    }
}
