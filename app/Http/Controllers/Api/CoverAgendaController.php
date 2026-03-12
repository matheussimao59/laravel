<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CoverAgendaController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = DB::table('cover_agenda_items')->where('user_id', $user->id)->orderByDesc('updated_at');
        if ($request->has('printed')) {
            $query->where('printed', $request->boolean('printed'));
        }

        return response()->json([
            'items' => $query->limit((int) $request->input('limit', 120))->get()->map(fn ($row) => $this->mapRow($row))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $columns = $this->resolveImageColumns();

        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'string', 'max:120'],
            'front_image' => ['required', 'string'],
            'back_image' => ['required', 'string'],
            'printed' => ['nullable', 'boolean'],
            'printed_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para capa.', 'errors' => $validator->errors()], 422);
        }

        $frontValue = $this->normalizeImageValue((string) $request->input('front_image'), (int) $user->id, 'front', $columns);
        $backValue = $this->normalizeImageValue((string) $request->input('back_image'), (int) $user->id, 'back', $columns);

        $payload = [
            'user_id' => $user->id,
            'order_id' => trim((string) $request->input('order_id')),
            'printed' => $request->boolean('printed', false),
            'printed_at' => $request->input('printed_at'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $payload[$columns['front']] = $frontValue;
        $payload[$columns['back']] = $backValue;

        $id = DB::table('cover_agenda_items')->insertGetId($payload);

        $row = DB::table('cover_agenda_items')->where('id', $id)->first();

        return response()->json([
            'message' => 'Capa salva com sucesso.',
            'item' => $row ? $this->mapRow($row) : null,
        ], 201);
    }

    public function show(Request $request, string $cover): JsonResponse
    {
        $row = $this->findOwnedRow($request, $cover);
        if (!$row) {
            return response()->json(['message' => 'Capa nao encontrada.'], 404);
        }

        return response()->json(['item' => $this->mapRow($row)]);
    }

    public function update(Request $request, string $cover): JsonResponse
    {
        $row = $this->findOwnedRow($request, $cover);
        if (!$row) {
            return response()->json(['message' => 'Capa nao encontrada.'], 404);
        }

        $columns = $this->resolveImageColumns();

        $validator = Validator::make($request->all(), [
            'order_id' => ['sometimes', 'string', 'max:120'],
            'front_image' => ['sometimes', 'string'],
            'back_image' => ['sometimes', 'string'],
            'printed' => ['sometimes', 'boolean'],
            'printed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para capa.', 'errors' => $validator->errors()], 422);
        }

        $payload = [
            'order_id' => $request->has('order_id') ? trim((string) $request->input('order_id')) : $row->order_id,
            'printed' => $request->has('printed') ? $request->boolean('printed') : (bool) $row->printed,
            'printed_at' => $request->exists('printed_at') ? $request->input('printed_at') : $row->printed_at,
            'updated_at' => now(),
        ];
        $payload[$columns['front']] = $request->has('front_image')
            ? $this->normalizeImageValue((string) $request->input('front_image'), (int) $row->user_id, 'front', $columns)
            : ($row->{$columns['front']} ?? null);
        $payload[$columns['back']] = $request->has('back_image')
            ? $this->normalizeImageValue((string) $request->input('back_image'), (int) $row->user_id, 'back', $columns)
            : ($row->{$columns['back']} ?? null);

        DB::table('cover_agenda_items')->where('id', (int) $cover)->update($payload);

        $updated = DB::table('cover_agenda_items')->where('id', (int) $cover)->first();

        return response()->json([
            'message' => 'Capa atualizada com sucesso.',
            'item' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $cover): JsonResponse
    {
        $row = $this->findOwnedRow($request, $cover);
        if (!$row) {
            return response()->json(['message' => 'Capa nao encontrada.'], 404);
        }

        DB::table('cover_agenda_items')->where('id', (int) $cover)->delete();

        return response()->json(['message' => 'Capa excluida com sucesso.', 'id' => $cover]);
    }

    public function markPrinted(Request $request, string $cover): JsonResponse
    {
        $row = $this->findOwnedRow($request, $cover);
        if (!$row) {
            return response()->json(['message' => 'Capa nao encontrada.'], 404);
        }

        $printed = $request->boolean('printed', true);
        $printedAt = $request->exists('printed_at')
            ? $request->input('printed_at')
            : ($printed ? now()->toDateTimeString() : null);

        DB::table('cover_agenda_items')->where('id', (int) $cover)->update([
            'printed' => $printed,
            'printed_at' => $printedAt,
            'updated_at' => now(),
        ]);

        $updated = DB::table('cover_agenda_items')->where('id', (int) $cover)->first();

        return response()->json([
            'message' => $printed ? 'Capa marcada como impressa.' : 'Capa retornou para a fila.',
            'item' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    private function findOwnedRow(Request $request, string $cover): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('cover_agenda_items')->where('id', (int) $cover)->where('user_id', $user->id)->first();
    }

    private function mapRow(object $row): array
    {
        $columns = $this->resolveImageColumns();

        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'order_id' => $row->order_id,
            'front_image' => $row->{$columns['front']} ?? null,
            'back_image' => $row->{$columns['back']} ?? null,
            'printed' => (bool) $row->printed,
            'printed_at' => $row->printed_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array{front: string, back: string}
     */
    private function resolveImageColumns(): array
    {
        if (Schema::hasColumn('cover_agenda_items', 'front_image') && Schema::hasColumn('cover_agenda_items', 'back_image')) {
            return ['front' => 'front_image', 'back' => 'back_image'];
        }

        return ['front' => 'front_image_path', 'back' => 'back_image_path'];
    }

    /**
     * @param array{front: string, back: string} $columns
     */
    private function normalizeImageValue(string $value, int $userId, string $side, array $columns): string
    {
        if ($columns['front'] === 'front_image' && $columns['back'] === 'back_image') {
            return $value;
        }

        if (!Str::startsWith($value, 'data:')) {
            return $value;
        }

        return $this->storeLegacyImage($value, $userId, $side);
    }

    private function storeLegacyImage(string $dataUrl, int $userId, string $side): string
    {
        if (!preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUrl, $matches)) {
            return $dataUrl;
        }

        $mime = strtolower($matches[1]);
        $encoded = $matches[2];
        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            return $dataUrl;
        }

        $extension = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };

        $relativeDir = 'uploads/cover-agenda/' . $userId;
        $absoluteDir = public_path($relativeDir);
        if (!File::isDirectory($absoluteDir)) {
            File::makeDirectory($absoluteDir, 0755, true);
        }

        $filename = $side . '-' . Str::uuid() . '.' . $extension;
        File::put($absoluteDir . DIRECTORY_SEPARATOR . $filename, $binary);

        return rtrim(config('app.url'), '/') . '/' . trim($relativeDir . '/' . $filename, '/');
    }
}
