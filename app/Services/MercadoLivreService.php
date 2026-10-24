<?php

namespace App\Services;

use App\Models\MercadoLivreAccount;
use App\Models\MlBotGroup;
use App\Models\MlBotItem;
use App\Models\MlBotOrder;
use App\Models\MlBotSetting;
use App\Models\User;
use App\Support\ExternalServiceException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
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

    private function refreshAccountToken(MercadoLivreAccount $account): string
    {
        ['client_id' => $clientId, 'client_secret' => $clientSecret] = $this->oauthCredentials();
        $refreshToken = trim((string) $account->refresh_token);

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new ExternalServiceException('Sessao do Mercado Livre expirada. Reconecte sua conta.', 401);
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post($this->baseUrl() . '/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]);

        $payload = $this->decodeResponse($response, 'ml_token_refresh_failed');
        $expiresIn = max(0, (int) ($payload['expires_in'] ?? 0));
        $refreshExpiresIn = max(0, (int) ($payload['refresh_token_expires_in'] ?? 0));

        $account->access_token = trim((string) ($payload['access_token'] ?? ''));
        if (trim((string) ($payload['refresh_token'] ?? '')) !== '') {
            $account->refresh_token = trim((string) $payload['refresh_token']);
        }
        $account->token_type = trim((string) ($payload['token_type'] ?? $account->token_type ?? ''));
        $account->scope = trim((string) ($payload['scope'] ?? $account->scope ?? ''));
        $account->expires_at = $expiresIn > 0 ? now()->addSeconds($expiresIn) : null;
        if ($refreshExpiresIn > 0) {
            $account->refresh_expires_at = now()->addSeconds($refreshExpiresIn);
        }
        $account->save();

        $token = trim((string) $account->access_token);
        if ($token === '') {
            throw new ExternalServiceException('Mercado Livre nao retornou um token valido. Reconecte sua conta.', 401);
        }

        return $token;
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
        bool $includeItemThumbs = true,
        ?array $knownSeller = null,
    ): array {
        $seller = is_array($knownSeller) && (int) ($knownSeller['id'] ?? 0) > 0
            ? $knownSeller
            : $this->request('/users/me', $accessToken);
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

        $thumbs = [];
        if ($includeItemThumbs) {
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
        }

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

    public function saveOAuthAccount(User $user, array $tokenPayload): array
    {
        $account = MercadoLivreAccount::firstOrNew([
            'user_id' => $user->id,
        ]);

        $expiresIn = max(0, (int) ($tokenPayload['expires_in'] ?? 0));
        $refreshExpiresIn = max(0, (int) ($tokenPayload['refresh_token_expires_in'] ?? 0));

        $account->access_token = trim((string) ($tokenPayload['access_token'] ?? ''));
        $account->refresh_token = trim((string) ($tokenPayload['refresh_token'] ?? ''));
        $account->token_type = trim((string) ($tokenPayload['token_type'] ?? ''));
        $account->scope = trim((string) ($tokenPayload['scope'] ?? ''));
        $account->expires_at = $expiresIn > 0 ? now()->addSeconds($expiresIn) : null;
        $account->refresh_expires_at = $refreshExpiresIn > 0 ? now()->addSeconds($refreshExpiresIn) : null;
        $account->save();

        return $this->accountStatusForUser($user);
    }

    public function accountStatusForUser(User $user): array
    {
        $account = $user->mercadoLivreAccount()->first();

        return [
            'connected' => $account !== null && trim((string) $account->access_token) !== '',
            'seller' => $this->sellerFromAccount($account),
            'expires_at' => $account?->expires_at?->toIso8601String(),
            'refresh_expires_at' => $account?->refresh_expires_at?->toIso8601String(),
            'last_synced_at' => $account?->last_synced_at?->toIso8601String(),
            'has_refresh_token' => $account !== null && trim((string) $account->refresh_token) !== '',
        ];
    }

    public function cachedSellerForUser(User $user): ?array
    {
        return $this->sellerFromAccount($user->mercadoLivreAccount()->first());
    }

    public function disconnectAccountForUser(User $user): void
    {
        $user->mercadoLivreAccount()->delete();
    }

    public function accessTokenForUser(User $user, bool $forceRefresh = false): string
    {
        $account = $user->mercadoLivreAccount()->first();
        $token = trim((string) ($account?->access_token ?? ''));

        if ($token === '') {
            throw new ExternalServiceException('Conta Mercado Livre nao conectada para este usuario.', 422);
        }

        if ($account && ($forceRefresh || ($account->expires_at && $account->expires_at->subMinutes(5)->isPast()))) {
            return $this->refreshAccountToken($account);
        }

        return $token;
    }

    public function rememberSellerForUser(User $user, array $seller): void
    {
        $account = MercadoLivreAccount::firstOrNew([
            'user_id' => $user->id,
        ]);

        $account->seller_id = ($seller['id'] ?? null) !== null ? (int) $seller['id'] : null;
        $account->seller_nickname = trim((string) ($seller['nickname'] ?? '')) ?: null;
        $account->seller_first_name = trim((string) ($seller['first_name'] ?? '')) ?: null;
        $account->seller_last_name = trim((string) ($seller['last_name'] ?? '')) ?: null;
        $account->seller_payload = $seller;
        $account->last_synced_at = now();
        $account->save();
    }

    public function sellerProductsForUser(User $user, int $limit = 50, int $offset = 0, string $status = 'active,paused,closed', bool $retried = false): array
    {
        $accessToken = $this->accessTokenForUser($user);
        $account = $user->mercadoLivreAccount()->first();
        $seller = $this->sellerFromAccount($account);

        try {
            if (!$seller || (int) ($seller['id'] ?? 0) <= 0) {
                $seller = $this->request('/users/me', $accessToken);
                if (is_array($seller)) {
                    $this->rememberSellerForUser($user, $seller);
                }
            }

            $sellerId = (int) ($seller['id'] ?? 0);
            if ($sellerId <= 0) {
                throw new ExternalServiceException('Conta Mercado Livre sem seller valido.', 422);
            }

            $query = http_build_query([
                'status' => $status,
                'limit' => max(1, min(50, $limit)),
                'offset' => max(0, $offset),
            ]);
            $search = $this->request("/users/{$sellerId}/items/search?{$query}", $accessToken);
            $ids = array_values(array_filter(array_map('strval', is_array($search['results'] ?? null) ? $search['results'] : [])));
            $items = $this->fetchItemsDetails($ids, $accessToken);

            return [
                'seller' => $seller,
                'paging' => is_array($search['paging'] ?? null) ? $search['paging'] : [
                    'total' => count($ids),
                    'offset' => $offset,
                    'limit' => $limit,
                ],
                'products' => array_map(fn (array $item) => $this->mapSellerProduct($item), $items),
            ];
        } catch (ExternalServiceException $exception) {
            if (!$retried && $this->isInvalidAccessToken($exception)) {
                $this->accessTokenForUser($user, true);
                return $this->sellerProductsForUser($user, $limit, $offset, $status, true);
            }

            throw $exception;
        }
    }

    public function updateSellerProductForUser(User $user, string $itemId, array $payload): array
    {
        $accessToken = $this->accessTokenForUser($user);
        $body = [];

        if (array_key_exists('title', $payload)) {
            $body['title'] = trim((string) $payload['title']);
        }
        if (array_key_exists('price', $payload)) {
            $body['price'] = round((float) $payload['price'], 2);
        }
        if (array_key_exists('status', $payload)) {
            $body['status'] = trim((string) $payload['status']);
        }
        if (array_key_exists('available_quantity', $payload)) {
            $body['available_quantity'] = max(0, (int) $payload['available_quantity']);
        }
        if (array_key_exists('seller_sku', $payload)) {
            $body['attributes'] = [[
                'id' => 'SELLER_SKU',
                'value_name' => trim((string) $payload['seller_sku']),
            ]];
        }

        if ($body === [] && !array_key_exists('description', $payload)) {
            throw new ExternalServiceException('Nenhum campo valido para atualizar o anuncio.', 422);
        }

        if ($body !== []) {
            $this->request('/items/' . rawurlencode($itemId), $accessToken, 'PUT', $body);
        }

        if (array_key_exists('description', $payload)) {
            $this->request('/items/' . rawurlencode($itemId) . '/description', $accessToken, 'PUT', [
                'plain_text' => (string) $payload['description'],
            ]);
        }

        $item = $this->request('/items/' . rawurlencode($itemId), $accessToken);
        if (is_array($item)) {
            $item['description'] = $this->fetchItemDescription($itemId, $accessToken);
        }

        return $this->mapSellerProduct(is_array($item) ? $item : []);
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

        // SaaS: credenciais do painel do admin (global_ml_oauth_config) tem
        // PRIORIDADE; o .env funciona como fallback enquanto o painel estiver vazio.
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

        if ($envCredentials['client_id'] !== '' && $envCredentials['client_secret'] !== '') {
            return $envCredentials;
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
        $response = null;
        $attempts = 3;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $client = Http::acceptJson()
                ->timeout(45)
                ->withToken($accessToken)
                ->withHeaders($headers);

            $response = match (strtoupper($method)) {
                'POST' => $client->post($this->baseUrl() . $path, $payload ?? []),
                'PUT' => $client->put($this->baseUrl() . $path, $payload ?? []),
                default => $client->get($this->baseUrl() . $path),
            };

            if ($response->status() !== 429 || $attempt === $attempts) {
                break;
            }

            usleep($attempt * 700000);
        }

        if (!$response instanceof Response) {
            throw new ExternalServiceException('Falha ao comunicar com o Mercado Livre.', 502);
        }

        return $this->decodeResponse($response, $path);
    }

    private function decodeResponse(Response $response, string $context): mixed
    {
        $parsed = $response->json();
        if ($parsed === null && trim($response->body()) !== '') {
            $parsed = $response->body();
        }

        if (!$response->successful()) {
            if ($response->status() === 429) {
                throw new ExternalServiceException(
                    'Mercado Livre limitou temporariamente a consulta. Aguarde alguns segundos e tente novamente.',
                    429,
                    $parsed
                );
            }

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

    private function fetchItemsDetails(array $itemIds, string $accessToken): array
    {
        $items = [];
        $chunkSize = 20;

        for ($i = 0; $i < count($itemIds); $i += $chunkSize) {
            $chunk = array_slice($itemIds, $i, $chunkSize);
            if ($chunk === []) {
                continue;
            }

            $query = 'ids=' . implode(',', array_map('rawurlencode', $chunk));
            $rows = $this->request('/items?' . $query, $accessToken);

            foreach (is_array($rows) ? $rows : [] as $row) {
                $body = is_array($row['body'] ?? null) ? $row['body'] : [];
                if ($body !== []) {
                    $itemId = trim((string) ($body['id'] ?? ''));
                    if ($itemId !== '') {
                        $body['description'] = $this->fetchItemDescription($itemId, $accessToken);
                    }
                    $items[] = $body;
                }
            }
        }

        return $items;
    }

    private function fetchItemDescription(string $itemId, string $accessToken): string
    {
        try {
            $payload = $this->request('/items/' . rawurlencode($itemId) . '/description', $accessToken);
            return trim((string) ($payload['plain_text'] ?? $payload['text'] ?? ''));
        } catch (ExternalServiceException) {
            return '';
        }
    }

    private function isInvalidAccessToken(ExternalServiceException $exception): bool
    {
        $text = strtolower($exception->getMessage() . ' ' . json_encode($exception->details(), JSON_UNESCAPED_UNICODE));
        return str_contains($text, 'invalid access token') || str_contains($text, 'unauthorized');
    }

    private function mapSellerProduct(array $item): array
    {
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
        $sellerSku = '';
        foreach ($attributes as $attribute) {
            $id = strtoupper(trim((string) ($attribute['id'] ?? '')));
            if (in_array($id, ['SELLER_SKU', 'SKU'], true)) {
                $sellerSku = trim((string) ($attribute['value_name'] ?? $attribute['value_id'] ?? ''));
                break;
            }
        }

        return [
            'id' => trim((string) ($item['id'] ?? '')),
            'title' => trim((string) ($item['title'] ?? '')),
            'price' => (float) ($item['price'] ?? 0),
            'status' => trim((string) ($item['status'] ?? '')),
            'available_quantity' => (int) ($item['available_quantity'] ?? 0),
            'sold_quantity' => (int) ($item['sold_quantity'] ?? 0),
            'permalink' => trim((string) ($item['permalink'] ?? '')),
            'thumbnail' => trim((string) ($item['thumbnail'] ?? '')),
            'seller_sku' => $sellerSku,
            'description' => trim((string) ($item['description'] ?? '')),
            'category_id' => trim((string) ($item['category_id'] ?? '')),
            'listing_type_id' => trim((string) ($item['listing_type_id'] ?? '')),
            'date_created' => trim((string) ($item['date_created'] ?? '')),
            'last_updated' => trim((string) ($item['last_updated'] ?? '')),
        ];
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

    private function sellerFromAccount(?MercadoLivreAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        if (is_array($account->seller_payload) && $account->seller_payload !== []) {
            return $account->seller_payload;
        }

        if (($account->seller_id ?? 0) <= 0) {
            return null;
        }

        return [
            'id' => (int) $account->seller_id,
            'nickname' => $account->seller_nickname,
            'first_name' => $account->seller_first_name,
            'last_name' => $account->seller_last_name,
        ];
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

        $idList = array_map('strval', array_keys($shipmentIds));

        // 1) Detalhe do envio em paralelo (status/datas para resolver data do envio).
        $detailPaths = [];
        foreach ($idList as $id) {
            $detailPaths[$id] = "/shipments/{$id}";
        }
        $detailResponses = $this->poolGet($detailPaths, $accessToken, 10);
        $shipmentMap = [];
        foreach ($detailResponses as $id => $detail) {
            if (is_array($detail) && $detail !== []) {
                $shipmentMap[(string) $id] = $detail;
            }
        }

        // 2) Custos do envio em paralelo (1 request por envio).
        $costPaths = [];
        foreach ($idList as $id) {
            $costPaths[$id] = "/shipments/{$id}/costs";
        }
        $costResponses = $this->poolGet($costPaths, $accessToken, 10);
        $shipmentCostMap = [];
        $fallbackIds = [];
        foreach ($costResponses as $id => $payload) {
            $idStr = (string) $id;
            if (!is_array($payload)) {
                $shipmentCostMap[$idStr] = [
                    'sellerCost' => 0.0,
                    'raw' => null,
                ];
                $fallbackIds[$idStr] = $idStr;
                continue;
            }
            $sellerCost = $this->extractShipmentSellerCost($payload);
            $shipmentCostMap[$idStr] = [
                'sellerCost' => $sellerCost,
                'raw' => $payload,
            ];
            if ($sellerCost <= 0) {
                $fallbackIds[$idStr] = $idStr;
            }
        }

        // 3) Fallback apenas nos envios sem custo conhecido (rota antiga de payments).
        if ($fallbackIds !== []) {
            $fallbackPaths = [];
            foreach (array_keys($fallbackIds) as $id) {
                $fallbackPaths[$id] = "/shipments/{$id}/payments";
            }
            $fallbackResponses = $this->poolGet($fallbackPaths, $accessToken, 10, ['x-format-new' => 'true']);
            foreach ($fallbackResponses as $id => $payload) {
                $idStr = (string) $id;
                if (!is_array($payload)) {
                    continue;
                }
                $sellerCost = $this->extractShipmentSellerCost($payload);
                if ($sellerCost > 0) {
                    $shipmentCostMap[$idStr] = [
                        'sellerCost' => $sellerCost,
                        'raw' => $payload,
                    ];
                }
            }
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

    private function extractShipmentSellerCost(mixed $payload): float
    {
        if (is_array($payload)) {
            if ($this->isListArray($payload)) {
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
                if (!$this->isListArray($payload[$field] ?? null)) {
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

    private function isListArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
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
        $orderIds = [];
        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId > 0) {
                $orderIds[(string) $orderId] = $orderId;
            }
        }

        if ($orderIds === []) {
            return;
        }

        // 1) Busca toda a lista de pagamentos em paralelo (1 request por pedido).
        $paths = [];
        foreach ($orderIds as $orderId) {
            $paths[(string) $orderId] = "/orders/{$orderId}/payments";
        }
        $listResponses = $this->poolGet($paths, $accessToken, 10);

        $rawPaymentsByOrder = [];
        $missingDetailList = [];
        foreach ($orderIds as $orderId) {
            $key = (string) $orderId;
            $raw = array_key_exists($key, $listResponses) ? $listResponses[$key] : null;
            if (is_array($raw) && array_is_list($raw)) {
                $rawPaymentsByOrder[$key] = $raw;
                continue;
            }
            $rawPaymentsByOrder[$key] = [];
            $missingDetailList[$key] = $orderId;
        }

        // 2) Fallback pontual: pedido cuja lista falhou busca no detalhe do pedido.
        if ($missingDetailList !== []) {
            $detailPaths = [];
            foreach ($missingDetailList as $orderId) {
                $detailPaths[(string) $orderId] = "/orders/{$orderId}";
            }
            $detailResponses = $this->poolGet($detailPaths, $accessToken, 10);
            foreach ($detailResponses as $key => $detail) {
                if (is_array($detail) && is_array($detail['payments'] ?? null)) {
                    $rawPaymentsByOrder[(string) $key] = is_array($detail['payments']) ? $detail['payments'] : [];
                }
            }
        }

        // 3) Enriquecimento fino (/v1/payments/{id}) apenas quando a lista nao
        //    trouxe nenhuma evidencia de tarifa (caso raro) — evita 1-2 requests
        //    por pedido no caminho padrao.
        $breakdownPaths = [];
        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $key = (string) $orderId;
            $rawPayments = $rawPaymentsByOrder[$key] ?? [];
            if ($this->paymentsHaveFeeEvidence($rawPayments)) {
                continue;
            }
            $paymentIds = $this->extractPaymentIds($rawPayments);
            foreach ($paymentIds as $paymentId) {
                $breakdownPaths[$paymentId] = "/v1/payments/{$paymentId}";
            }
        }

        $breakdownByPayment = [];
        if ($breakdownPaths !== []) {
            $breakdownResponses = $this->poolGet($breakdownPaths, $accessToken, 10);
            foreach ($breakdownResponses as $paymentId => $detail) {
                if (is_array($detail) && $detail !== []) {
                    $breakdownByPayment[(string) $paymentId] = $detail;
                }
            }
        }

        foreach ($orders as &$order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                $order['payments'] = [];
                continue;
            }

            $key = (string) $orderId;
            $rawPayments = $rawPaymentsByOrder[$key] ?? [];

            if ($this->paymentsHaveFeeEvidence($rawPayments) || $breakdownByPayment === []) {
                $order['payments'] = $rawPayments;
                continue;
            }

            $details = [];
            foreach ($this->extractPaymentIds($rawPayments) as $paymentId) {
                $detail = $breakdownByPayment[$paymentId] ?? null;
                if ($detail !== null) {
                    $details[] = $detail;
                }
            }

            $order['payments'] = $details !== [] ? $details : $rawPayments;
        }
        unset($order);
    }

    private function paymentsHaveFeeEvidence(array $payments): bool
    {
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = strtolower((string) ($payment['status'] ?? ''));
            if ($status === 'cancelled') {
                continue;
            }
            if ((float) ($payment['marketplace_fee'] ?? 0) > 0) {
                return true;
            }
            if ((float) ($payment['fee_amount'] ?? 0) > 0) {
                return true;
            }
            if ((float) ($payment['taxes_amount'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
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

    /**
     * Executa varias requisicoes GET em paralelo (Http::pool), respeitando um
     * limite de concorrencia para nao trocar rate limit do Mercado Livre.
     * Chaves numericas/string dos Paths chaveam o resultado decodificado
     * (null quando a request falha — chamada "safe").
     *
     * @param array<string, string> $pathsByKey
     */
    private function poolGet(array $pathsByKey, string $accessToken, int $concurrency = 10, array $headers = []): array
    {
        if ($pathsByKey === []) {
            return [];
        }

        $keys = array_map('strval', array_keys($pathsByKey));
        $decoded = [];

        for ($i = 0; $i < count($keys); $i += max(1, $concurrency)) {
            $chunk = array_slice($keys, $i, max(1, $concurrency));
            if ($i > 0) {
                // Pausa curta entre lotes para respeitar o rate limit do ML.
                usleep(120000);
            }
            $responses = Http::pool(function (Pool $pool) use ($chunk, $pathsByKey, $accessToken, $headers) {
                foreach ($chunk as $key) {
                    $entry = $pool->as($key)
                        ->acceptJson()
                        ->timeout(45)
                        ->withToken($accessToken);
                    if ($headers !== []) {
                        $entry = $entry->withHeaders($headers);
                    }
                    $entry->get($this->baseUrl() . $pathsByKey[$key]);
                }
            });

            foreach ($responses as $key => $response) {
                $json = null;
                try {
                    if ($response instanceof Response && $response->successful()) {
                        $parsed = $response->json();
                        $json = $parsed ?? [];
                    }
                } catch (\Throwable) {
                    $json = null;
                }
                $decoded[$key] = $json;
            }
        }

        return $decoded;
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

    /* ============================================================
     * Bot Mila — pos-venda: grupos, varredura de vendas e conversas
     * ============================================================ */

    public function botConfigForUser(User $user): array
    {
        $settings = MlBotSetting::firstOrNew(['user_id' => $user->id]);
        $groups = MlBotGroup::with('items')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        return [
            'start_date' => $settings->exists
                ? (string) $settings->start_date->format('Y-m-d')
                : '2026-10-23',
            'groups' => $groups
                ->map(fn (MlBotGroup $group) => $this->mapBotGroup($group))
                ->all(),
        ];
    }

    public function saveBotConfig(User $user, string $startDate): array
    {
        $settings = MlBotSetting::updateOrCreate(
            ['user_id' => $user->id],
            ['start_date' => $startDate]
        );

        return [
            'start_date' => $settings->start_date->format('Y-m-d'),
        ];
    }

    public function storeBotGroup(User $user, string $name, ?string $template): MlBotGroup
    {
        return MlBotGroup::create([
            'user_id' => $user->id,
            'name' => $name,
            'template' => $template,
        ]);
    }

    public function updateBotGroup(User $user, int $groupId, string $name, ?string $template): ?MlBotGroup
    {
        $group = MlBotGroup::where('user_id', $user->id)->where('id', $groupId)->first();
        if (!$group) {
            return null;
        }

        $group->fill([
            'name' => $name,
            'template' => $template,
        ]);
        $group->save();

        return $group->fresh(['items']);
    }

    public function destroyBotGroup(User $user, int $groupId): bool
    {
        $group = MlBotGroup::where('user_id', $user->id)->where('id', $groupId)->first();
        if (!$group) {
            return false;
        }

        // Itens do grupo sao removidos em cascata (FK);
        // pedidos antigos passam a apontar para group_id null.
        $group->delete();

        return true;
    }

    public function replaceBotGroupItems(User $user, int $groupId, array $items): ?MlBotGroup
    {
        $group = MlBotGroup::where('user_id', $user->id)->where('id', $groupId)->first();
        if (!$group) {
            return null;
        }

        DB::transaction(function () use ($user, $group, $items) {
            MlBotItem::where('user_id', $user->id)->where('group_id', $group->id)->delete();

            foreach ($items as $item) {
                $itemId = trim((string) ($item['item_id'] ?? ''));
                if ($itemId === '') {
                    continue;
                }

                // Um anuncio pertence a um grupo por vez: updateOrCreate
                // move o anuncio de outro grupo quando ja estava marcado.
                MlBotItem::updateOrCreate(
                    ['user_id' => $user->id, 'item_id' => $itemId],
                    [
                        'group_id' => $group->id,
                        'title' => self::inferredString($item['title'] ?? null),
                        'thumbnail' => self::inferredString($item['thumbnail'] ?? null),
                        'seller_sku' => self::inferredString($item['seller_sku'] ?? null, 120),
                        'price' => isset($item['price']) && is_numeric($item['price'])
                            ? (float) $item['price']
                            : null,
                        'item_status' => self::inferredString($item['status'] ?? null, 30),
                    ]
                );
            }
        });

        return $group->fresh(['items']);
    }

    /**
     * Le a conversa de pos-venda de um pedido/carrinho.
     * Conta mensagens de comprador e de vendedor para dirimir:
     * se o cliente ja escreveu no chat (aba propria) ou se o vendedor
     * ja respondeu (evita mensagem duplicada).
     */
    public function readOrderConversation(
        string $accessToken,
        int $sellerId,
        ?int $packId,
        int $orderId,
    ): array {
        $paths = [];
        if (($packId ?? 0) > 0) {
            $paths[] = "/messages/packs/{$packId}/sellers/{$sellerId}?offset=0&limit=30";
        }
        // Pedido sem carrinho responde na rota de packs usando o id do pedido.
        $paths[] = "/messages/packs/{$orderId}/sellers/{$sellerId}?offset=0&limit=30";

        $headers = ['x-format-new' => 'true'];

        foreach ($paths as $path) {
            try {
                $payload = $this->request($path, $accessToken, 'GET', null, $headers);
                $conversation = is_array($payload['conversation'] ?? null) ? $payload['conversation'] : [];

                $buyerMessages = 0;
                $sellerMessages = 0;
                $lastBuyerText = null;
                $lastBuyerDate = null;

                foreach ($conversation as $message) {
                    if (!is_array($message)) {
                        continue;
                    }
                    $fromUserId = (int) (($message['from']['user_id'] ?? 0));
                    if ($fromUserId === 0) {
                        continue;
                    }

                    if ($fromUserId === $sellerId) {
                        $sellerMessages++;
                        continue;
                    }

                    $buyerMessages++;
                    $date = trim((string) ($message['date'] ?? ''));
                    $text = trim((string) ($message['message'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    if ($lastBuyerDate === null || $date === '' || $date > $lastBuyerDate) {
                        $lastBuyerDate = $date !== '' ? $date : $lastBuyerDate;
                        $lastBuyerText = $text;
                    }
                }

                return [
                    'ok' => true,
                    'buyer_messages' => $buyerMessages,
                    'seller_messages' => $sellerMessages,
                    'last_buyer_text' => $lastBuyerText,
                ];
            } catch (ExternalServiceException $exception) {
                // tenta o proximo path pedido sem carrinho
            }
        }

        return [
            'ok' => false,
            'buyer_messages' => 0,
            'seller_messages' => 0,
            'last_buyer_text' => null,
            'error' => 'ml_conversation_unavailable',
        ];
    }

    /**
     * Varredura que gera os comandos do bot:
     * - traz pedidos a partir da data salva (padrao 23/10/2026);
     * - casa os itens com anuncios marcados em grupos;
     * - grava/atualiza um comando por pedido no banco;
     * - checa o chat: cliente ja respondeu? vendedor ja respondeu?
     */
    public function scanBotOrders(User $user, ?string $startDate = null, int $maxPages = 10): array
    {
        // Varredura demorada (dezenas de chamadas ao ML): amplia o tempo
        // de execucao quando o PHP-FPM permite; se negado, o orçamento
        // de tempo da fase de conversas mantem o request dentro do teto.
        @set_time_limit(300);

        $startedAt = microtime(true);
        $conversationBudgetMs = 20000;

        $accessToken = $this->accessTokenForUser($user);
        $account = $user->mercadoLivreAccount()->first();
        $knownSeller = $this->sellerFromAccount($account);
        if (is_array($knownSeller) && (int) ($knownSeller['id'] ?? 0) <= 0) {
            $knownSeller = null;
        }

        $settings = MlBotSetting::where('user_id', $user->id)->first();
        $startDate = trim((string) ($startDate ?? '')) ?: ($settings?->start_date?->format('Y-m-d') ?? '2026-10-23');
        $fromDate = Carbon::parse($startDate)->startOfDay()->toIso8601String();

        $payload = $this->syncOrders(
            $accessToken,
            $fromDate,
            null,
            false,
            false,
            max(1, $maxPages),
            false,
            $knownSeller
        );

        $sellerId = (int) $payload['seller']['id'];

        // Vinculo anuncio -> grupo salvo no banco (a escolha do usuario).
        $groupIdByItem = [];
        foreach (MlBotItem::where('user_id', $user->id)->get() as $item) {
            $groupIdByItem[$item->item_id] = (int) $item->group_id;
        }
        $groupsById = MlBotGroup::where('user_id', $user->id)->get()->keyBy('id');

        // Agrupa pedidos por carrinho (quando existir): um comando por compra.
        $targets = [];
        foreach ($payload['orders'] as $order) {
            if (!is_array($order)) {
                continue;
            }
            $status = strtolower(trim((string) ($order['status'] ?? '')));
            if ($status === '' || str_contains($status, 'cancel') || str_contains($status, 'invalid')) {
                continue;
            }
            $rows = is_array($order['order_items'] ?? null) ? $order['order_items'] : [];
            if ($rows === []) {
                continue;
            }

            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $firstItem = $rows[0]['item'] ?? null;
            $itemIds = [];
            $qty = 0;
            $groupId = null;
            foreach ($rows as $row) {
                $itemId = trim((string) ($row['item']['id'] ?? ''));
                if ($itemId !== '') {
                    $itemIds[$itemId] = true;
                }
                $rowQty = (float) ($row['quantity'] ?? 1);
                $qty += $rowQty > 0 ? $rowQty : 1;
                if ($groupId === null && $itemId !== '' && isset($groupIdByItem[$itemId])) {
                    $candidate = (int) $groupIdByItem[$itemId];
                    if (isset($groupsById[$candidate])) {
                        $groupId = $candidate;
                    }
                }
            }

            // So interessa venda de anuncio marcado em algum grupo.
            if ($groupId === null) {
                continue;
            }

            $packId = (int) ($order['pack_id'] ?? 0);
            $key = $packId > 0 ? 'p' . $packId : 'o' . $orderId;

            if (!isset($targets[$key])) {
                $targets[$key] = [
                    'key' => $key,
                    'order_ids' => [],
                    'pack_id' => $packId > 0 ? $packId : null,
                    'buyer_id' => (int) ($order['buyer']['id'] ?? ($order['payer']['id'] ?? 0)),
                    'title' => (string) ($firstItem['title'] ?? 'Produto Mercado Livre'),
                    'thumbnail' => (string) ($firstItem['thumbnail'] ?? ''),
                    'sku' => (string) ($firstItem['seller_sku'] ?? ''),
                    'qty' => 0,
                    'group_id' => $groupId,
                    'order_date' => (string) ($order['date_created'] ?? ''),
                ];
            } elseif ($targets[$key]['group_id'] === null && $groupId !== null) {
                $targets[$key]['group_id'] = $groupId;
            }

            // Importante: arrays PHP sao copiados por valor — pedidos e
            // unidades acumulam direto no alvo guardado em $targets,
            // senao o loop de gravacao receberia listas vazias.
            $targets[$key]['order_ids'][] = $orderId;
            $targets[$key]['qty'] += $qty;
        }

        $targetsChecked = 0;
        $conversationChecks = [];

        foreach ($targets as $bucket) {
            $orderDate = trim((string) $bucket['order_date']) !== ''
                ? Carbon::parse($bucket['order_date'])
                : now();

            $existing = MlBotOrder::where('user_id', $user->id)
                ->whereIn('order_id', $bucket['order_ids'])
                ->get()
                ->keyBy('order_id');

            $firstRow = null;
            foreach ($bucket['order_ids'] as $orderId) {
                $row = $existing->get($orderId) ?? new MlBotOrder([
                    'user_id' => $user->id,
                    'order_id' => $orderId,
                    'status' => MlBotOrder::STATUS_PENDING,
                    'buyer_replied' => false,
                    'seller_messaged' => false,
                    'buyer_messages' => 0,
                    'seller_messages' => 0,
                ]);

                $row->pack_id = $bucket['pack_id'];
                $row->buyer_id = $bucket['buyer_id'] ?: $row->buyer_id;
                $row->group_id = $bucket['group_id'];
                $row->title = $bucket['title'];
                $row->thumbnail = trim((string) $bucket['thumbnail']) !== '' ? (string) $bucket['thumbnail'] : $row->thumbnail;
                $row->sku = $bucket['sku'];
                $row->qty = $bucket['qty'];
                $row->order_date = $orderDate;
                $row->save();

                if ($firstRow === null) {
                    $firstRow = $row;
                }
            }

            // Checagem de conversa por carrinho (uma chamada por compra).
            $needsCheck = $firstRow !== null
                && $firstRow->status !== MlBotOrder::STATUS_SKIPPED
                && (
                    $firstRow->buyer_replied === false
                    || (
                        $firstRow->status === MlBotOrder::STATUS_PENDING
                        && !$firstRow->seller_messaged
                    )
                )
                && (
                    $firstRow->conversation_checked_at === null
                    || $firstRow->conversation_checked_at->copy()->addMinutes(30)->isPast()
                );

            if ($needsCheck) {
                $conversationChecks[] = [
                    'bucket' => $bucket,
                    'rows' => $existing,
                    'order_ids' => $bucket['order_ids'],
                    'pack_id' => $bucket['pack_id'],
                    'first_order_id' => $bucket['order_ids'][0],
                    'seller_id' => $sellerId,
                ];
            }
        }

        foreach ($conversationChecks as $check) {
            // Conta tentativas, inclusive falhas, sem impedir a catalogacao
            // dos pedidos que ficaram para uma proxima varredura.
            if ($targetsChecked >= 60 || (microtime(true) - $startedAt) * 1000 >= $conversationBudgetMs) {
                break;
            }

            $targetsChecked++;
            $conversation = $this->readOrderConversation(
                $accessToken,
                (int) $check['seller_id'],
                $check['pack_id'],
                (int) $check['first_order_id']
            );
            if (!($conversation['ok'] ?? false)) {
                continue;
            }

            usleep(120000); // respeita limites de chamadas do chat

            $buyerMessages = (int) $conversation['buyer_messages'];
            $sellerMessages = (int) $conversation['seller_messages'];

            MlBotOrder::where('user_id', $user->id)
                ->whereIn('order_id', $check['order_ids'])
                ->each(function (MlBotOrder $row) use ($buyerMessages, $sellerMessages, $check) {
                    $justChecked = now();

                    $row->buyer_messages = $buyerMessages;
                    $row->seller_messages = $sellerMessages;
                    $row->buyer_replied = $buyerMessages > 0 || $row->buyer_replied;
                    $row->seller_messaged = $sellerMessages > 0 || $row->seller_messaged;
                    $row->conversation_checked_at = $justChecked;

                    // Vendedor ja mandou algo no chat e o comando continuava
                    // pendente: marca como enviado para nao repetir mensagem.
                    if (
                        $row->seller_messaged
                        && $row->status === MlBotOrder::STATUS_PENDING
                        && trim((string) ($row->sent_message ?? '')) === ''
                    ) {
                        $row->status = MlBotOrder::STATUS_SENT;
                        $row->sent_at = $row->sent_at ?? $justChecked;
                    }

                    $row->save();
                });
        }

        return [
            'start_date' => $startDate,
            'scanned_orders' => is_countable($payload['orders']) ? count($payload['orders']) : 0,
            'eligible' => is_countable($targets) ? count($targets) : 0,
            'conversation_checks' => $targetsChecked,
            'counts' => $this->botOrderCounts($user),
        ];
    }

    public function botOrderCounts(User $user): array
    {
        $counts = ['pending' => 0, 'replied' => 0, 'sent' => 0, 'skipped' => 0];

        foreach (
            MlBotOrder::where('user_id', $user->id)
                ->selectRaw('status, buyer_replied, count(*) as total')
                ->groupBy('status', 'buyer_replied')
                ->get() as $row
        ) {
            $bucket = $row->status === MlBotOrder::STATUS_SENT
                ? 'sent'
                : ($row->status === MlBotOrder::STATUS_SKIPPED
                    ? 'skipped'
                    : (($row->buyer_replied ? 1 : 0) ? 'replied' : 'pending'));
            $counts[$bucket] += (int) $row->total;
        }

        return $counts;
    }

    public function mapBotGroup(MlBotGroup $group): array
    {
        return [
            'id' => (int) $group->id,
            'name' => (string) $group->name,
            'template' => (string) ($group->template ?? ''),
            'item_ids' => $group->items
                ->map(fn (MlBotItem $item) => (string) $item->item_id)
                ->values()
                ->all(),
            'items' => $group->items
                ->map(fn (MlBotItem $item) => [
                    'item_id' => (string) $item->item_id,
                    'title' => (string) ($item->title ?? ''),
                    'thumbnail' => (string) ($item->thumbnail ?? ''),
                    'seller_sku' => (string) ($item->seller_sku ?? ''),
                    'price' => $item->price !== null ? (float) $item->price : null,
                    'status' => (string) ($item->item_status ?? ''),
                ])
                ->values()
                ->all(),
        ];
    }

    public function botOrdersForUser(User $user): array
    {
        return MlBotOrder::with('group:id,name,template')
            ->where('user_id', $user->id)
            ->orderByDesc('order_id')
            ->get()
            ->map(function (MlBotOrder $row) {
                return [
                    'order_id' => (int) $row->order_id,
                    'pack_id' => $row->pack_id,
                    'buyer_id' => $row->buyer_id,
                    'title' => (string) ($row->title ?? ''),
                    'thumbnail' => (string) ($row->thumbnail ?? ''),
                    'sku' => (string) ($row->sku ?? ''),
                    'qty' => (float) $row->qty,
                    'status' => (string) $row->status,
                    'bucket' => $row->bucket(),
                    'buyer_replied' => (bool) $row->buyer_replied,
                    'buyer_messages' => (int) $row->buyer_messages,
                    'seller_messages' => (int) $row->seller_messages,
                    'order_date' => $row->order_date?->toIso8601String(),
                    'sent_at' => $row->sent_at?->toIso8601String(),
                    'sent_message' => $row->sent_message,
                    'group_id' => $row->group_id,
                    'group_name' => (string) ($row->group?->name ?? ''),
                    'template' => (string) ($row->group?->template ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private static function inferredString(mixed $value, int $max = 200): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        if ($trimmed === '') {
            return null;
        }
        return mb_substr($trimmed, 0, $max);
    }
}
