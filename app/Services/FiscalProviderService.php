<?php

namespace App\Services;

use App\Support\ExternalServiceException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class FiscalProviderService
{
    public function emit(array $payload): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $orderTotal = (float) ($payload['order_total'] ?? 0);
        $invoiceSeries = trim((string) ($payload['invoice_series'] ?? '1'));
        $environment = strtolower((string) ($payload['environment'] ?? 'homologacao')) === 'producao'
            ? 'producao'
            : 'homologacao';
        $providerName = trim((string) ($payload['provider_name'] ?? 'nuvemfiscal'));
        $orderTitle = trim((string) ($payload['order_title'] ?? 'Pedido sem titulo'));
        $buyerName = trim((string) ($payload['buyer_name'] ?? 'Cliente final'));
        $emitter = is_array($payload['emitter'] ?? null) ? $payload['emitter'] : [];

        $missingEmitter = $this->validateEmitter($emitter);
        if ($missingEmitter !== []) {
            throw new ExternalServiceException(
                'Campos fiscais obrigatorios ausentes: ' . implode(', ', $missingEmitter) . '.'
            );
        }

        $providerToken = trim((string) config('services.fiscal_provider.token'));
        $defaultBaseUrl = trim((string) config('services.fiscal_provider.base_url', 'https://api.nuvemfiscal.com.br'));
        $providerBaseUrl = trim((string) ($payload['provider_base_url'] ?? $defaultBaseUrl));
        $issuePath = trim((string) config('services.fiscal_provider.issue_path', '/v1/nfe'));

        if ($providerToken === '' || $providerBaseUrl === '') {
            return [
                'status' => 'draft_pending_provider',
                'provider_name' => $providerName,
                'provider_ref' => sprintf('nf-%d-%d', $orderId, time()),
                'invoice_number' => '',
                'access_key' => '',
                'message' => 'Integracao fiscal ainda nao configurada. Documento salvo como rascunho.',
                'environment' => $environment,
                'invoice_series' => $invoiceSeries,
                'order_id' => $orderId,
                'order_total' => $orderTotal,
            ];
        }

        $issueUrl = rtrim($providerBaseUrl, '/') . '/' . ltrim($issuePath, '/');
        $requestBody = [
            'ambiente' => $environment,
            'serie' => $invoiceSeries,
            'numero' => null,
            'natureza_operacao' => 'Venda de mercadoria',
            'emitente' => [
                'cnpj' => (string) ($emitter['cnpj'] ?? ''),
                'inscricao_estadual' => (string) ($emitter['ie'] ?? ''),
                'razao_social' => (string) ($emitter['razao_social'] ?? ''),
                'nome_fantasia' => (string) ($emitter['nome_fantasia'] ?? ''),
                'regime_tributario' => (string) ($emitter['regime_tributario'] ?? ''),
                'endereco' => [
                    'cep' => (string) ($emitter['cep'] ?? ''),
                    'logradouro' => (string) ($emitter['logradouro'] ?? ''),
                    'numero' => (string) ($emitter['numero'] ?? ''),
                    'complemento' => (string) ($emitter['complemento'] ?? ''),
                    'bairro' => (string) ($emitter['bairro'] ?? ''),
                    'municipio' => (string) ($emitter['cidade'] ?? ''),
                    'uf' => (string) ($emitter['uf'] ?? ''),
                ],
            ],
            'certificado' => [
                'referencia' => (string) ($emitter['certificate_provider_ref'] ?? ''),
            ],
            'destinatario' => [
                'nome' => $buyerName,
            ],
            'itens' => [[
                'codigo' => 'ORDER-' . $orderId,
                'descricao' => $orderTitle,
                'quantidade' => 1,
                'valor_unitario' => $orderTotal,
                'valor_total' => $orderTotal,
            ]],
            'valor_total' => $orderTotal,
            'referencia_externa' => (string) $orderId,
        ];

        $response = Http::withToken($providerToken)
            ->acceptJson()
            ->post($issueUrl, $requestBody);

        $parsed = $this->decodeResponse($response);

        if (!$response->successful()) {
            throw new ExternalServiceException(
                $this->firstString($parsed['message'] ?? null, $parsed['error'] ?? null, $response->body())
                    ?: 'Falha ao enviar documento para o emissor.',
                400,
                $parsed
            );
        }

        $rawStatus = $this->firstString(
            $parsed['status'] ?? null,
            $parsed['situacao'] ?? null,
            $parsed['state'] ?? null,
            'pending_provider'
        );

        return [
            'status' => $this->toProviderStatus($rawStatus),
            'provider_name' => $providerName,
            'provider_ref' => $this->firstString(
                $parsed['id'] ?? null,
                $parsed['uuid'] ?? null,
                $parsed['referencia'] ?? null,
                $parsed['reference'] ?? null,
                $parsed['ref'] ?? null,
            ),
            'invoice_number' => $this->firstString(
                $parsed['numero'] ?? null,
                $parsed['numero_nf'] ?? null,
                $parsed['invoice_number'] ?? null,
            ),
            'access_key' => $this->firstString(
                $parsed['chave'] ?? null,
                $parsed['chave_acesso'] ?? null,
                $parsed['access_key'] ?? null,
            ),
            'xml_url' => $this->firstString(
                $parsed['xml_url'] ?? null,
                $parsed['url_xml'] ?? null,
                $parsed['download_xml_url'] ?? null,
            ),
            'pdf_url' => $this->firstString(
                $parsed['pdf_url'] ?? null,
                $parsed['url_pdf'] ?? null,
                $parsed['download_pdf_url'] ?? null,
            ),
            'message' => $this->firstString(
                $parsed['mensagem'] ?? null,
                $parsed['message'] ?? null,
                $rawStatus
            ) ?: 'Documento enviado ao emissor.',
        ];
    }

    public function status(string $providerRef, ?string $providerBaseUrl = null): array
    {
        $providerToken = trim((string) config('services.fiscal_provider.token'));
        $defaultBaseUrl = trim((string) config('services.fiscal_provider.base_url', 'https://api.nuvemfiscal.com.br'));
        $baseUrl = trim((string) ($providerBaseUrl ?: $defaultBaseUrl));
        $statusPathTemplate = trim((string) config('services.fiscal_provider.status_path_template', '/v1/nfe/{id}'));

        if ($providerToken === '' || $baseUrl === '') {
            return [
                'status' => 'draft_pending_provider',
                'message' => 'Integracao fiscal ainda nao configurada.',
            ];
        }

        $statusPath = str_replace('{id}', urlencode($providerRef), $statusPathTemplate);
        $statusUrl = rtrim($baseUrl, '/') . '/' . ltrim($statusPath, '/');

        $response = Http::withToken($providerToken)
            ->acceptJson()
            ->get($statusUrl);

        $parsed = $this->decodeResponse($response);

        if (!$response->successful()) {
            throw new ExternalServiceException(
                $this->firstString($parsed['message'] ?? null, $parsed['error'] ?? null, $response->body())
                    ?: 'Falha ao consultar status no emissor.',
                400,
                $parsed
            );
        }

        $rawStatus = $this->firstString(
            $parsed['status'] ?? null,
            $parsed['situacao'] ?? null,
            $parsed['state'] ?? null,
            'pending_provider'
        );

        return [
            'status' => $this->toProviderStatus($rawStatus),
            'provider_ref' => $providerRef,
            'invoice_number' => $this->firstString(
                $parsed['numero'] ?? null,
                $parsed['numero_nf'] ?? null,
                $parsed['invoice_number'] ?? null,
            ),
            'access_key' => $this->firstString(
                $parsed['chave'] ?? null,
                $parsed['chave_acesso'] ?? null,
                $parsed['access_key'] ?? null,
            ),
            'xml_url' => $this->firstString(
                $parsed['xml_url'] ?? null,
                $parsed['url_xml'] ?? null,
                $parsed['download_xml_url'] ?? null,
            ),
            'pdf_url' => $this->firstString(
                $parsed['pdf_url'] ?? null,
                $parsed['url_pdf'] ?? null,
                $parsed['download_pdf_url'] ?? null,
            ),
            'message' => $this->firstString(
                $parsed['mensagem'] ?? null,
                $parsed['message'] ?? null,
                $rawStatus
            ),
        ];
    }

    private function decodeResponse(Response $response): array
    {
        $parsed = $response->json();
        return is_array($parsed) ? $parsed : ['raw' => $response->body()];
    }

    private function validateEmitter(array $emitter): array
    {
        $required = [
            'cnpj' => 'CNPJ',
            'razao_social' => 'Razao social',
            'regime_tributario' => 'Regime tributario',
            'logradouro' => 'Logradouro',
            'numero' => 'Numero',
            'bairro' => 'Bairro',
            'cidade' => 'Cidade',
            'uf' => 'UF',
            'cep' => 'CEP',
            'certificate_provider_ref' => 'Referencia do certificado',
        ];

        $missing = [];
        foreach ($required as $field => $label) {
            if (trim((string) ($emitter[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    private function firstString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $string = trim((string) ($value ?? ''));
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function toProviderStatus(string $rawStatus): string
    {
        $status = strtolower($rawStatus);
        if (str_contains($status, 'autoriz') || str_contains($status, 'approved') || str_contains($status, 'success')) {
            return 'authorized';
        }
        if (str_contains($status, 'error') || str_contains($status, 'reject') || str_contains($status, 'denied')) {
            return 'error';
        }
        if (str_contains($status, 'draft')) {
            return 'draft_pending_provider';
        }

        return 'pending_provider';
    }
}
