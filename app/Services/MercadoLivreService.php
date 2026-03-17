<?php

namespace App\Services;

use App\Support\ExternalServiceException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class MercadoLivreService
{
    public function exchangeToken(string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        ['client_id' => $clientId, 'client_secret' => $clientSecret] = $this->oauthCredentials();

        if ($clientId === '' || $clientSecret === '') {
            throw new ExternalServiceException(
                'Configure Client ID e Client Secret do Mercado Livre no painel ou no ambiente da API Laravel.',
                500
            );
        }

        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier) {
            $body['code_verifier'] = $codeVerifier;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post($this->baseUrl() . '/oauth/token', $body);

        return $this->decodeResponse($response, 'ml_token_exchange_failed');
    }

    public function sendCustomization(
        string $accessToken,
        int $sellerId,
        int $orderId,
        ?int $packId,
        string $message,
    ): array {
        $optionId = 'REQUEST_VARIANTS';

        if (($packId ?? 0) > 0) {
            try {
                $this->request("/messages/action_guide/packs/{$packId}/caps_available?tag=post_sale", $accessToken);
            } catch (ExternalServiceException) {
                // Nao bloqueia o fluxo.
            }

            try {
                $optionsPayload = $this->request(
                    "/messages/action_guide/packs/{$packId}/options?tag=post_sale",
                    $accessToken
                );
                $found = $this->findRequestVariantsOption($optionsPayload);
                if ($found !== null) {
                    $optionId = $found;
                }
            } catch (ExternalServiceException) {
                // Mantem fallback REQUEST_VARIANTS.
            }
        }

        $attempts = [];
        if (($packId ?? 0) > 0) {
            $attempts[] = [
                'path' => "/messages/action_guide/packs/{$packId}/sellers/{$sellerId}?tag=post_sale",
                'body' => ['text' => $message, 'option_id' => $optionId],
            ];
            $attempts[] = [
                'path' => "/messages/action_guide/packs/{$packId}/option/{$optionId}/sellers/{$sellerId}?tag=post_sale",
                'body' => ['text' => $message],
            ];
            $attempts[] = [
                'path' => "/messages/action_guide/packs/{$packId}/options/{$optionId}/sellers/{$sellerId}?tag=post_sale",
                'body' => ['text' => $message],
            ];
        }

        $attempts[] = [
            'path' => "/messages/action_guide/orders/{$orderId}/sellers/{$sellerId}?tag=post_sale",
            'body' => ['text' => $message, 'option_id' => $optionId],
        ];
        $attempts[] = [
            'path' => "/messages/action_guide/orders/{$orderId}/option/{$optionId}/sellers/{$sellerId}?tag=post_sale",
            'body' => ['text' => $message],
        ];
        $attempts[] = [
            'path' => "/messages/action_guide/orders/{$orderId}/options/{$optionId}/sellers/{$sellerId}?tag=post_sale",
            'body' => ['text' => $message],
        ];

        $lastDetails = null;

        foreach ($attempts as $attempt) {
            try {
                $response = $this->request($attempt['path'], $accessToken, 'POST', $attempt['body']);

                return [
                    'ok' => true,
                    'order_id' => $orderId,
                    'pack_id' => $packId,
                    'option_id' => $optionId,
                    'path_used' => $attempt['path'],
                    'response' => $response,
                ];
            } catch (ExternalServiceException $exception) {
                $lastDetails = $exception->details() ?: $exception->getMessage();
            }
        }

        return [
            'ok' => false,
            'error' => 'ml_message_send_failed',
            'message' => 'Nao foi possivel enviar mensagem de personalizacao.',
            'details' => $lastDetails,
        ];
    }

    public function syncOrders(
        string $accessToken,
        string $fromDate,
        ?string $toDate,
        bool $includePaymentsDetails,
        bool $includeShipmentsDetails,
        int $maxPages,
    ): array {
        $seller = $this->request('/users/me', $accessToken);
        $sellerId = (int) ($seller['id'] ?? 0);

        if ($sellerId <= 0) {
            throw new ExternalServiceException('Resposta invalida do seller no Mercado Livre.');
        }

        $allOrders = [];
        $limit = 50;
        $offset = 0;
        $pages = max(1, min(500, $maxPages > 0 ? $maxPages : 120));

        for ($page = 0; $page < $pages; $page++) {
            $query = [
                'seller' => (string) $sellerId,
                'sort' => 'date_desc',
                'limit' => (string) $limit,
                'offset' => (string) $offset,
                'order.date_created.from' => $fromDate,
            ];
            if ($toDate) {
                $query['order.date_created.to'] = $toDate;
            }

            $orders = $this->request('/orders/search?' . http_build_query($query), $accessToken);
            $results = is_array($orders['results'] ?? null) ? $orders['results'] : [];
            $allOrders = [...$allOrders, ...$results];

            if (count($results) < $limit) {
                break;
            }

            $offset += $limit;
        }

        $itemIds = [];
        foreach ($allOrders as $order) {
            foreach (($order['order_items'] ?? []) as $row) {
                $itemId = trim((string) ($row['item']['id'] ?? ''));
                if ($itemId !== '') {
                    $itemIds[$itemId] = true;
                }
            }
        }

        $thumbs = $this->fetchItemsThumbs(array_keys($itemIds), $accessToken);

        $enrichedOrders = array_map(function (array $order) use ($thumbs) {
            foreach (($order['order_items'] ?? []) as $index => $row) {
                $itemId = trim((string) ($row['item']['id'] ?? ''));
                if ($itemId !== '' && isset($thumbs[$itemId])) {
                    $order['order_items'][$index]['item']['thumbnail'] = $thumbs[$itemId];
                }
            }

            return $order;
        }, $allOrders);

        if ($includePaymentsDetails) {
            $this->attachPaymentsToOrders($enrichedOrders, $accessToken);
        } else {
            foreach ($enrichedOrders as &$order) {
                $order['payments'] = [];
            }
            unset($order);
        }

        if ($includeShipmentsDetails) {
            $this->attachShipmentsToOrders($enrichedOrders, $accessToken);
        } else {
            foreach ($enrichedOrders as &$order) {
                $order['shipping_date_resolved'] = $this->resolveShippingDate($order);
            }
            unset($order);
        }

        return [
            'seller' => $seller,
            'orders' => $enrichedOrders,
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.mercado_livre.base_url', 'https://api.mercadolibre.com'), '/');
    }

    private function oauthCredentials(): array
    {
        $envCredentials = [
            'client_id' => trim((string) config('services.mercado_livre.client_id')),
            'client_secret' => trim((string) config('services.mercado_livre.client_secret')),
        ];

        if ($envCredentials['client_id'] !== '' && $envCredentials['client_secret'] !== '') {
            return $envCredentials;
        }

        $stored = DB::table('app_settings')
            ->where('id', 'global_ml_oauth_config')
            ->whereNull('user_id')
            ->first();

        if ($stored) {
            $config = json_decode((string) ($stored->config_data ?? '{}'), true);
            if (is_array($config)) {
                $clientId = trim((string) ($config['client_id'] ?? ''));
                $encryptedSecret = trim((string) ($config['client_secret'] ?? ''));
                $clientSecret = '';

                if ($encryptedSecret !== '') {
                    try {
                        $clientSecret = trim(Crypt::decryptString($encryptedSecret));
                    } catch (\Throwable) {
                        $clientSecret = '';
                    }
                }

                if ($clientId !== '' && $clientSecret !== '') {
                    return [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                    ];
                }
            }
        }

        return $envCredentials;
    }

    private function request(
        string $path,
        string $accessToken,
        string $method = 'GET',
        ?array $payload = null,
        array $headers = [],
    ): mixed {
        $client = Http::acceptJson()
            ->timeout(45)
            ->withToken($accessToken)
            ->withHeaders($headers);

        $response = match (strtoupper($method)) {
            'POST' => $client->post($this->baseUrl() . $path, $payload ?? []),
            'PUT' => $client->put($this->baseUrl() . $path, $payload ?? []),
            default => $client->get($this->baseUrl() . $path),
        };

        return $this->decodeResponse($response, $path);
    }

    private function decodeResponse(Response $response, string $context): mixed
    {
        $parsed = $response->json();
        if ($parsed === null && trim($response->body()) !== '') {
            $parsed = $response->body();
        }

        if (!$response->successful()) {
            throw new ExternalServiceException(
                json_encode([
                    'path' => $context,
                    'status' => $response->status(),
                    'details' => $parsed,
                ], JSON_UNESCAPED_UNICODE),
                400,
                $parsed
            );
        }

        return $parsed ?? [];
    }

    private function fetchItemsThumbs(array $itemIds, string $accessToken): array
    {
        $thumbById = [];
        $chunkSize = 20;

        for ($i = 0; $i < count($itemIds); $i += $chunkSize) {
            $chunk = array_slice($itemIds, $i, $chunkSize);
            if ($chunk === []) {
                continue;
            }

            $query = implode('&', array_map(
                static fn (string $id) => 'ids=' . urlencode($id),
                $chunk
            ));
            $rows = $this->request('/items?' . $query, $accessToken);

            foreach (is_array($rows) ? $rows : [] as $row) {
                $body = is_array($row['body'] ?? null) ? $row['body'] : [];
                $id = trim((string) ($body['id'] ?? ''));
                $thumb = trim((string) ($body['thumbnail'] ?? ''));
                if ($id !== '' && $thumb !== '') {
                    $thumbById[$id] = $thumb;
                }
            }
        }

        return $thumbById;
    }

    private function findRequestVariantsOption(mixed $optionsPayload): ?string
    {
        $options = [];
        if (is_array($optionsPayload)) {
            $options = $optionsPayload;
        } elseif (is_array($optionsPayload['options'] ?? null)) {
            $options = $optionsPayload['options'];
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $id = trim((string) ($option['id'] ?? ''));
            $code = strtoupper(trim((string) ($option['code'] ?? $option['name'] ?? $option['tag'] ?? '')));
            if ($id !== '' && str_contains($code, 'REQUEST_VARIANTS')) {
                return $id;
            }
        }

        return null;
    }

    private function attachShipmentsToOrders(array &$orders, string $accessToken): void
    {
        $shipmentIds = [];

        foreach ($orders as $order) {
            $id = trim((string) ($order['shipping']['id'] ?? ''));
            if ($id !== '') {
                $shipmentIds[$id] = true;
            }
        }

        if ($shipmentIds === []) {
            foreach ($orders as &$order) {
                $order['shipping_date_resolved'] = $this->resolveShippingDate($order);
            }
            unset($order);
            return;
        }

        $shipmentMap = [];
        $shipmentCostMap = [];

        foreach (array_keys($shipmentIds) as $shipmentId) {
            $details = $this->safeRequest("/shipments/{$shipmentId}", $accessToken);
            $costInfo = $this->fetchShipmentCosts($shipmentId, $accessToken);

            if (($costInfo['sellerCost'] ?? 0) <= 0) {
                $fallback = $this->fetchShipmentPaymentsCost($shipmentId, $accessToken);
                if (($fallback['sellerCost'] ?? 0) > 0) {
                    $costInfo = $fallback;
                }
            }

            if ($details !== null) {
                $shipmentMap[$shipmentId] = $details;
            }
            $shipmentCostMap[$shipmentId] = $costInfo;
        }

        foreach ($orders as &$order) {
            $shipmentId = trim((string) ($order['shipping']['id'] ?? ''));
            if ($shipmentId !== '' && isset($shipmentMap[$shipmentId]) && is_array($shipmentMap[$shipmentId])) {
                $order['shipping'] = [
                    ...($order['shipping'] ?? []),
                    ...$shipmentMap[$shipmentId],
                ];
            }

            $costInfo = $shipmentId !== '' ? ($shipmentCostMap[$shipmentId] ?? null) : null;
            $order['shipping_cost_seller'] = max(0, (float) ($costInfo['sellerCost'] ?? 0));
            if (($costInfo['raw'] ?? null) !== null) {
                $order['shipping_cost_raw'] = $costInfo['raw'];
            }
            $order['shipping_date_resolved'] = $this->resolveShippingDate($order);
        }
        unset($order);
    }

    private function fetchShipmentCosts(string $shipmentId, string $accessToken): array
    {
        $payload = $this->safeRequest("/shipments/{$shipmentId}/costs", $accessToken);

        return [
            'sellerCost' => $this->extractShipmentSellerCost($payload),
            'raw' => $payload,
        ];
    }

    private function fetchShipmentPaymentsCost(string $shipmentId, string $accessToken): array
    {
        $payload = $this->safeRequest(
            "/shipments/{$shipmentId}/payments",
            $accessToken,
            'GET',
            null,
            ['x-format-new' => 'true']
        );

        return [
            'sellerCost' => $this->extractShipmentSellerCost($payload),
            'raw' => $payload,
        ];
    }

    private function extractShipmentSellerCost(mixed $payload): float
    {
        if (is_array($payload)) {
            if (array_is_list($payload)) {
                $sum = 0;
                foreach ($payload as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $payer = strtolower((string) ($entry['payer_type'] ?? $entry['payer'] ?? ''));
                    $amount = abs($this->amountFromObject($entry));
                    if ($amount > 0 && (str_contains($payer, 'seller') || str_contains($payer, 'sender'))) {
                        $sum += $amount;
                    }
                }
                if ($sum > 0) {
                    return $sum;
                }
            }

            foreach (['senders', 'costs'] as $field) {
                if (!array_is_list($payload[$field] ?? null)) {
                    continue;
                }

                $sum = 0;
                foreach ($payload[$field] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $payer = strtolower((string) ($entry['payer_type'] ?? $entry['payer'] ?? ''));
                    $amount = abs($this->amountFromObject($entry));
                    if ($field === 'costs' && !(str_contains($payer, 'seller') || str_contains($payer, 'sender'))) {
                        continue;
                    }
                    $sum += $amount;
                }

                if ($sum > 0) {
                    return $sum;
                }
            }

            foreach (['sender', 'seller'] as $field) {
                if (is_array($payload[$field] ?? null)) {
                    $amount = abs($this->amountFromObject($payload[$field]));
                    if ($amount > 0) {
                        return $amount;
                    }
                }
            }
        }

        return 0;
    }

    private function amountFromObject(array $row): float
    {
        foreach (['seller_cost', 'cost', 'amount', 'net_amount', 'gross_amount', 'total'] as $field) {
            $amount = is_numeric($row[$field] ?? null) ? (float) $row[$field] : 0;
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0;
    }

    private function attachPaymentsToOrders(array &$orders, string $accessToken): void
    {
        foreach ($orders as &$order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                $order['payments'] = [];
                continue;
            }

            $rawPayments = $this->fetchOrderPayments($orderId, $accessToken);
            $paymentIds = $this->extractPaymentIds($rawPayments);

            if ($paymentIds === []) {
                $order['payments'] = $rawPayments;
                continue;
            }

            $details = [];
            foreach ($paymentIds as $paymentId) {
                $detail = $this->fetchPaymentDetails($paymentId, $accessToken);
                if ($detail !== null) {
                    $details[] = $detail;
                }
            }

            $order['payments'] = $details !== [] ? $details : $rawPayments;
        }
        unset($order);
    }

    private function fetchOrderPayments(int $orderId, string $accessToken): array
    {
        $payments = $this->safeRequest("/orders/{$orderId}/payments", $accessToken);
        if (is_array($payments) && array_is_list($payments)) {
            return $payments;
        }

        $orderDetail = $this->safeRequest("/orders/{$orderId}", $accessToken);
        return is_array($orderDetail['payments'] ?? null) ? $orderDetail['payments'] : [];
    }

    private function extractPaymentIds(array $rawPayments): array
    {
        $ids = [];
        foreach ($rawPayments as $payment) {
            if (is_scalar($payment)) {
                $id = trim((string) $payment);
            } elseif (is_array($payment)) {
                $id = trim((string) ($payment['id'] ?? ''));
            } else {
                $id = '';
            }

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function fetchPaymentDetails(string $paymentId, string $accessToken): ?array
    {
        $detail = $this->safeRequest("/v1/payments/{$paymentId}", $accessToken);
        if (is_array($detail)) {
            return $detail;
        }

        $fallback = $this->safeRequest("/payments/{$paymentId}", $accessToken);
        return is_array($fallback) ? $fallback : null;
    }

    private function safeRequest(
        string $path,
        string $accessToken,
        string $method = 'GET',
        ?array $payload = null,
        array $headers = [],
    ): mixed {
        try {
            return $this->request($path, $accessToken, $method, $payload, $headers);
        } catch (ExternalServiceException) {
            return null;
        }
    }

    private function resolveShippingDate(array $order): string
    {
        $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
        $statusJoined = strtolower(trim(implode(' ', [
            (string) ($order['status'] ?? ''),
            (string) ($shipping['status'] ?? ''),
            (string) ($shipping['substatus'] ?? ''),
        ])));

        if (
            str_contains($statusJoined, 'shipped') ||
            str_contains($statusJoined, 'delivered') ||
            str_contains($statusJoined, 'in_transit')
        ) {
            return $this->firstValidDate([
                $shipping['shipped_at'] ?? null,
                $shipping['date_last_updated'] ?? null,
                $shipping['date_created'] ?? null,
                $order['date_created'] ?? null,
            ]) ?: (string) ($order['date_created'] ?? '');
        }

        return $this->firstValidDate([
            $shipping['estimated_delivery_time']['date'] ?? null,
            $shipping['date_created'] ?? null,
            $shipping['date_last_updated'] ?? null,
            $order['date_created'] ?? null,
        ]) ?: (string) ($order['date_created'] ?? '');
    }

    private function firstValidDate(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function fetchCompetitorPrices(string $itemId, string $accessToken): array
    {
        // Buscar item similar ou concorrentes via API do ML
        // Exemplo: buscar itens similares
        $similar = $this->safeRequest("/items/{$itemId}/similar", $accessToken);
        $competitors = [];

        if (is_array($similar)) {
            foreach ($similar as $item) {
                if (is_array($item) && isset($item['id'], $item['price'])) {
                    $competitors[] = [
                        'id' => $item['id'],
                        'price' => (float) $item['price'],
                        'title' => $item['title'] ?? '',
                    ];
                }
            }
        }

        return $competitors;
    }

    public function updateItemPrice(string $itemId, float $newPrice, string $accessToken): bool
    {
        try {
            $this->request("/items/{$itemId}", $accessToken, 'PUT', [
                'price' => $newPrice,
            ]);
            return true;
        } catch (ExternalServiceException) {
            return false;
        }
    }

    public function calculateReprice(\App\Models\MercadoLivreProduct $product, array $competitors): ?float
    {
        if (empty($competitors)) return null;

        // Estratégia simples: preço médio dos concorrentes - 1%
        $prices = array_column($competitors, 'price');
        $avgPrice = array_sum($prices) / count($prices);
        $suggestedPrice = $avgPrice * 0.99;

        // Verificar margem mínima
        if (!$product->isMarginValid($suggestedPrice)) {
            return null; // Não ajustar se margem insuficiente
        }

        return round($suggestedPrice, 2);
    }
}
