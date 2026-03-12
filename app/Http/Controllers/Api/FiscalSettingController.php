<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class FiscalSettingController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $row = DB::table('fiscal_settings')->where('user_id', $user->id)->first();

        return response()->json([
            'settings' => $row ? $this->mapRow($row) : $this->defaultPayload($user->id),
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'invoice_series' => ['nullable', 'string', 'max:20'],
            'environment' => ['nullable', 'in:homologacao,producao'],
            'provider_name' => ['nullable', 'string', 'max:60'],
            'provider_base_url' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:30'],
            'ie' => ['nullable', 'string', 'max:30'],
            'razao_social' => ['nullable', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'regime_tributario' => ['nullable', 'string', 'max:40'],
            'email_fiscal' => ['nullable', 'string', 'max:255'],
            'telefone_fiscal' => ['nullable', 'string', 'max:30'],
            'cep' => ['nullable', 'string', 'max:20'],
            'logradouro' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:30'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:255'],
            'cidade' => ['nullable', 'string', 'max:255'],
            'uf' => ['nullable', 'string', 'max:2'],
            'certificate_provider_ref' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para configuracao fiscal.', 'errors' => $validator->errors()], 422);
        }

        $payload = [
            'invoice_series' => (string) $request->input('invoice_series', '1'),
            'environment' => (string) $request->input('environment', 'homologacao'),
            'provider_name' => (string) $request->input('provider_name', 'nuvemfiscal'),
            'provider_base_url' => (string) $request->input('provider_base_url', 'https://api.nuvemfiscal.com.br'),
            'cnpj' => $request->input('cnpj'),
            'ie' => $request->input('ie'),
            'razao_social' => $request->input('razao_social'),
            'nome_fantasia' => $request->input('nome_fantasia'),
            'regime_tributario' => (string) $request->input('regime_tributario', 'simples_nacional'),
            'email_fiscal' => $request->input('email_fiscal'),
            'telefone_fiscal' => $request->input('telefone_fiscal'),
            'cep' => $request->input('cep'),
            'logradouro' => $request->input('logradouro'),
            'numero' => $request->input('numero'),
            'complemento' => $request->input('complemento'),
            'bairro' => $request->input('bairro'),
            'cidade' => $request->input('cidade'),
            'uf' => $request->input('uf') ? strtoupper((string) $request->input('uf')) : null,
            'certificate_provider_ref' => $request->input('certificate_provider_ref'),
            'updated_at' => now(),
        ];

        $existing = DB::table('fiscal_settings')->where('user_id', $user->id)->first();

        if ($existing) {
            DB::table('fiscal_settings')->where('user_id', $user->id)->update($payload);
        } else {
            DB::table('fiscal_settings')->insert([
                'user_id' => $user->id,
                ...$payload,
                'created_at' => now(),
            ]);
        }

        $row = DB::table('fiscal_settings')->where('user_id', $user->id)->first();

        return response()->json([
            'message' => 'Configuracoes fiscais salvas com sucesso.',
            'settings' => $row ? $this->mapRow($row) : $this->defaultPayload($user->id),
        ]);
    }

    private function mapRow(object $row): array
    {
        return [
            'user_id' => (string) $row->user_id,
            'invoice_series' => (string) ($row->invoice_series ?: '1'),
            'environment' => (string) ($row->environment ?: 'homologacao'),
            'provider_name' => (string) ($row->provider_name ?: 'nuvemfiscal'),
            'provider_base_url' => (string) ($row->provider_base_url ?: 'https://api.nuvemfiscal.com.br'),
            'cnpj' => (string) ($row->cnpj ?: ''),
            'ie' => (string) ($row->ie ?: ''),
            'razao_social' => (string) ($row->razao_social ?: ''),
            'nome_fantasia' => (string) ($row->nome_fantasia ?: ''),
            'regime_tributario' => (string) ($row->regime_tributario ?: 'simples_nacional'),
            'email_fiscal' => (string) ($row->email_fiscal ?: ''),
            'telefone_fiscal' => (string) ($row->telefone_fiscal ?: ''),
            'cep' => (string) ($row->cep ?: ''),
            'logradouro' => (string) ($row->logradouro ?: ''),
            'numero' => (string) ($row->numero ?: ''),
            'complemento' => (string) ($row->complemento ?: ''),
            'bairro' => (string) ($row->bairro ?: ''),
            'cidade' => (string) ($row->cidade ?: ''),
            'uf' => (string) ($row->uf ?: ''),
            'certificate_provider_ref' => (string) ($row->certificate_provider_ref ?: ''),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function defaultPayload(int $userId): array
    {
        return [
            'user_id' => (string) $userId,
            'invoice_series' => '1',
            'environment' => 'homologacao',
            'provider_name' => 'nuvemfiscal',
            'provider_base_url' => 'https://api.nuvemfiscal.com.br',
            'cnpj' => '',
            'ie' => '',
            'razao_social' => '',
            'nome_fantasia' => '',
            'regime_tributario' => 'simples_nacional',
            'email_fiscal' => '',
            'telefone_fiscal' => '',
            'cep' => '',
            'logradouro' => '',
            'numero' => '',
            'complemento' => '',
            'bairro' => '',
            'cidade' => '',
            'uf' => '',
            'certificate_provider_ref' => '',
            'created_at' => null,
            'updated_at' => null,
        ];
    }
}
