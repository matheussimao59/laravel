<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MercadoLivreConfigController
{
    private const SETTING_ID = 'global_ml_oauth_config';

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $row = $this->publicConfig();

        return response()->json([
            'config' => [
                'client_id' => trim((string) ($row['client_id'] ?? '')),
                'redirect_uri' => trim((string) ($row['redirect_uri'] ?? '')),
                'configured' => $this->isConfigured($row),
                'has_client_secret' => trim((string) ($row['client_secret'] ?? '')) !== '',
                'updated_at' => $row['updated_at'] ?? null,
                'source' => $row['source'] ?? 'panel',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (($user->role ?? null) !== 'admin') {
            return response()->json(['message' => 'Apenas administradores podem alterar a configuracao do Mercado Livre.'], 403);
        }

        $existing = $this->loadRow();
        $validator = Validator::make($request->all(), [
            'client_id' => ['required', 'string', 'max:120'],
            'redirect_uri' => ['required', 'url', 'max:255'],
            'client_secret' => [
                empty($existing['client_secret']) ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados invalidos para configuracao do Mercado Livre.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $secretInput = trim((string) $request->input('client_secret', ''));
        $secret = $secretInput !== '' ? $secretInput : (string) ($existing['client_secret'] ?? '');

        if ($secret === '') {
            return response()->json([
                'message' => 'Informe o Client Secret do Mercado Livre.',
                'errors' => ['client_secret' => ['Informe o Client Secret do Mercado Livre.']],
            ], 422);
        }

        $config = [
            'client_id' => trim((string) $request->input('client_id')),
            'redirect_uri' => trim((string) $request->input('redirect_uri')),
            'client_secret' => Crypt::encryptString($secret),
        ];

        $existingRow = DB::table('app_settings')
            ->where('id', self::SETTING_ID)
            ->whereNull('user_id')
            ->first();

        if ($existingRow) {
            DB::table('app_settings')
                ->where('id', self::SETTING_ID)
                ->whereNull('user_id')
                ->update([
                    'config_data' => json_encode($config),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('app_settings')->insert([
                'id' => self::SETTING_ID,
                'user_id' => null,
                'config_data' => json_encode($config),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Configuracao do Mercado Livre salva com sucesso.',
            'config' => [
                'client_id' => $config['client_id'],
                'redirect_uri' => $config['redirect_uri'],
                'configured' => true,
                'has_client_secret' => true,
            ],
        ]);
    }

    private function loadRow(): array
    {
        $row = DB::table('app_settings')
            ->where('id', self::SETTING_ID)
            ->whereNull('user_id')
            ->first();

        if (!$row) {
            return [];
        }

        $config = json_decode((string) ($row->config_data ?? '{}'), true);
        if (!is_array($config)) {
            $config = [];
        }

        $secret = '';
        $encryptedSecret = trim((string) ($config['client_secret'] ?? ''));
        if ($encryptedSecret !== '') {
            try {
                $secret = Crypt::decryptString($encryptedSecret);
            } catch (\Throwable) {
                $secret = '';
            }
        }

        return [
            'client_id' => $config['client_id'] ?? '',
            'redirect_uri' => $config['redirect_uri'] ?? '',
            'client_secret' => $secret,
            'updated_at' => $row->updated_at ?? null,
            'source' => 'panel',
        ];
    }

    private function publicConfig(): array
    {
        $envClientId = trim((string) config('services.mercado_livre.client_id', ''));
        $envClientSecret = trim((string) config('services.mercado_livre.client_secret', ''));
        if ($envClientId !== '' || $envClientSecret !== '') {
            return [
                'client_id' => $envClientId,
                'redirect_uri' => $this->defaultRedirectUri(),
                'client_secret' => $envClientSecret,
                'updated_at' => null,
                'source' => 'env',
            ];
        }

        return $this->loadRow();
    }

    private function defaultRedirectUri(): string
    {
        $frontendUrl = trim((string) env('FRONTEND_URL', ''));
        if ($frontendUrl === '') {
            $frontendUrl = trim((string) config('app.url', ''));
        }

        if ($frontendUrl === '') {
            return '';
        }

        return rtrim($frontendUrl, '/') . '/integracoes/mercado-livre/callback';
    }

    private function isConfigured(array $config): bool
    {
        return trim((string) ($config['client_id'] ?? '')) !== ''
            && trim((string) ($config['redirect_uri'] ?? '')) !== ''
            && trim((string) ($config['client_secret'] ?? '')) !== '';
    }
}
