<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AppSettingController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = DB::table('app_settings')
            ->where(function ($builder) use ($user) {
                $builder->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->orderByDesc('updated_at');

        $ids = $request->input('ids', []);
        if (is_string($ids) && $ids !== '') {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }

        if (is_array($ids) && $ids !== []) {
            $query->whereIn('id', $ids);
        }

        $prefix = trim((string) $request->query('prefix', ''));
        if ($prefix !== '') {
            $query->where('id', 'like', $prefix . '%');
        }

        return response()->json([
            'settings' => $query->get()->map(fn ($row) => $this->mapRow($row))->values(),
        ]);
    }

    public function show(Request $request, string $setting): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = DB::table('app_settings')->where('id', $setting);

        if ($this->isGlobalSetting($setting)) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $user->id);
        }

        $row = $query->first();

        if (!$row) {
            return response()->json(['message' => 'Configuracao nao encontrada.'], 404);
        }

        return response()->json(['setting' => $this->mapRow($row)]);
    }

    public function upsert(Request $request, string $setting): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $isGlobal = $this->isGlobalSetting($setting);
        if ($isGlobal && !$this->isAdmin($user)) {
            return response()->json(['message' => 'Apenas administradores podem alterar esta configuracao.'], 403);
        }

        $existingQuery = DB::table('app_settings')->where('id', $setting);
        if ($isGlobal) {
            $existingQuery->whereNull('user_id');
        } else {
            $existingQuery->where('user_id', $user->id);
        }
        $existing = $existingQuery->first();
        $targetUserId = $isGlobal ? null : $user->id;

        $payload = [
            'user_id' => $targetUserId,
            'config_data' => json_encode($request->input('config_data')),
            'updated_at' => now(),
        ];

        if ($existing) {
            $updateQuery = DB::table('app_settings')->where('id', $setting);
            if ($isGlobal) {
                $updateQuery->whereNull('user_id');
            } else {
                $updateQuery->where('user_id', $user->id);
            }
            $updateQuery->update($payload);
        } else {
            DB::table('app_settings')->insert([
                'id' => $setting,
                ...$payload,
                'created_at' => now(),
            ]);
        }

        $rowQuery = DB::table('app_settings')->where('id', $setting);
        if ($isGlobal) {
            $rowQuery->whereNull('user_id');
        } else {
            $rowQuery->where('user_id', $user->id);
        }
        $row = $rowQuery->first();

        return response()->json([
            'message' => 'Configuracao salva com sucesso.',
            'setting' => $row ? $this->mapRow($row) : null,
        ]);
    }

    public function destroy(Request $request, string $setting): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $isGlobal = $this->isGlobalSetting($setting);
        if ($isGlobal && !$this->isAdmin($user)) {
            return response()->json(['message' => 'Apenas administradores podem remover esta configuracao.'], 403);
        }

        $query = DB::table('app_settings')->where('id', $setting);
        if ($isGlobal) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $user->id);
        }
        $query->delete();

        return response()->json([
            'message' => 'Configuracao removida com sucesso.',
            'id' => $setting,
        ]);
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'user_id' => $row->user_id ? (string) $row->user_id : null,
            'config_data' => $this->decodeJson($row->config_data),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return $value;
    }

    private function isGlobalSetting(string $setting): bool
    {
        return str_starts_with($setting, 'global_');
    }

    private function isAdmin(object $user): bool
    {
        return (($user->role ?? null) === 'admin');
    }
}
