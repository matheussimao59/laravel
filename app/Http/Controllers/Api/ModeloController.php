<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class ModeloController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $modelsQuery = DB::table('modelos')
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id);

                if ($this->hasSharedColumns()) {
                    $query->orWhere('is_shared', true);
                }

                if ($this->hasAccessTable()) {
                    $query->orWhereExists(function ($subQuery) use ($user) {
                        $subQuery->selectRaw('1')
                            ->from('modelo_user_accesses')
                            ->whereColumn('modelo_user_accesses.modelo_id', 'modelos.id')
                            ->where('modelo_user_accesses.user_id', $user->id);
                    });
                }
            });

        $models = $modelsQuery
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => $this->mapRow($row, $user))
            ->values();

        return response()->json(['models' => $models]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sheet_size' => ['required', 'string', 'max:50'],
            'orientation' => ['required', 'string', 'max:50'],
            'pdf_base' => ['required', 'file', 'mimes:pdf,jpeg,png,jpg', 'max:20480'],
            'verso_base' => ['nullable', 'file', 'mimes:pdf,jpeg,png,jpg', 'max:20480'],
        ]);

        $pdfPath = null;
        $pdfName = null;
        $versoPath = null;
        $versoName = null;

        if ($request->hasFile('pdf_base')) {
            $pdfFile = $request->file('pdf_base');
            $pdfName = $pdfFile->getClientOriginalName();
            $pdfPath = $pdfFile->store('modelos', 'public');
        }

        if ($request->hasFile('verso_base')) {
            $versoFile = $request->file('verso_base');
            $versoName = $versoFile->getClientOriginalName();
            $versoPath = $versoFile->store('modelos', 'public');
        }

        $id = DB::table('modelos')->insertGetId([
            'user_id' => $user->id,
            'name' => trim($validated['name']),
            'sheet_size' => trim($validated['sheet_size']),
            'orientation' => trim($validated['orientation']),
            'pdf_name' => $pdfName,
            'pdf_path' => $pdfPath,
            'verso_name' => $versoName,
            'verso_path' => $versoPath,
            'editor_state' => json_encode($this->defaultEditorState()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('modelos')->where('id', $id)->first();

        return response()->json([
            'message' => 'Modelo criado com sucesso.',
            'model' => $row ? $this->mapRow($row, $user) : null,
        ]);
    }

    public function show(Request $request, $modelo): JsonResponse
    {
        $user = $request->user();
        $row = $this->getVisibleModelForUser($request, $modelo);
        if (!$row) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        return response()->json(['model' => $this->mapRow($row, $user)]);
    }

    public function accessUsers(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (($user->role ?? null) !== 'admin') {
            return response()->json(['message' => 'Apenas administradores podem gerenciar acesso aos modelos.'], 403);
        }

        $users = DB::table('users')
            ->select('id', 'name', 'email', 'role', 'is_active')
            ->where('id', '<>', $user->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role' => $row->role ? (string) $row->role : null,
                'is_active' => (bool) $row->is_active,
            ])
            ->values();

        return response()->json(['users' => $users]);
    }

    public function update(Request $request, $modelo): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validated = $request->validate([
            'editor_state' => ['nullable'],
            'pdf_base' => ['nullable', 'file', 'mimes:pdf,jpeg,png,jpg', 'max:20480'],
            'verso_base' => ['nullable', 'file', 'mimes:pdf,jpeg,png,jpg', 'max:20480'],
            'remove_verso' => ['nullable'],
        ]);

        $row = $this->getOwnedModelForUser($request, $modelo);
        if (!$row) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        $updates = [
            'updated_at' => now(),
        ];

        if ($request->has('editor_state')) {
            $editorState = $validated['editor_state'] ?? null;
            if (is_string($editorState)) {
                $decoded = json_decode($editorState, true);
                $editorState = is_array($decoded) ? $decoded : null;
            }
            $updates['editor_state'] = $editorState ? json_encode($editorState) : null;
        }

        if ($request->hasFile('pdf_base')) {
            if ($row->pdf_path) {
                Storage::disk('public')->delete(preg_replace('#^public/#', '', $row->pdf_path));
            }
            $pdfFile = $request->file('pdf_base');
            $updates['pdf_name'] = $pdfFile->getClientOriginalName();
            $updates['pdf_path'] = $pdfFile->store('modelos', 'public');
        }

        if ($request->boolean('remove_verso')) {
            if ($row->verso_path ?? null) {
                Storage::disk('public')->delete(preg_replace('#^public/#', '', $row->verso_path));
            }
            $updates['verso_name'] = null;
            $updates['verso_path'] = null;
        }

        if ($request->hasFile('verso_base')) {
            if ($row->verso_path ?? null) {
                Storage::disk('public')->delete(preg_replace('#^public/#', '', $row->verso_path));
            }
            $versoFile = $request->file('verso_base');
            $updates['verso_name'] = $versoFile->getClientOriginalName();
            $updates['verso_path'] = $versoFile->store('modelos', 'public');
        }

        DB::table('modelos')->where('id', $modelo)->update($updates);

        $updated = DB::table('modelos')->where('id', $modelo)->first();

        return response()->json([
            'message' => 'Modelo atualizado com sucesso.',
            'model' => $updated ? $this->mapRow($updated, $user) : null,
        ]);
    }

    public function share(Request $request, $modelo): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (($user->role ?? null) !== 'admin') {
            return response()->json(['message' => 'Apenas administradores podem disponibilizar modelos.'], 403);
        }

        if (!$this->hasSharedColumns()) {
            return response()->json(['message' => 'Atualize o banco de dados para compartilhar modelos.'], 409);
        }

        $row = $this->getOwnedModelForUser($request, $modelo);
        if (!$row) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        $validated = $request->validate([
            'is_shared' => ['required', 'boolean'],
        ]);

        $isShared = (bool) $validated['is_shared'];
        DB::table('modelos')
            ->where('id', $modelo)
            ->where('user_id', $user->id)
            ->update([
                'is_shared' => $isShared,
                'shared_at' => $isShared ? now() : null,
                'updated_at' => now(),
            ]);

        $updated = DB::table('modelos')->where('id', $modelo)->first();

        return response()->json([
            'message' => $isShared ? 'Modelo disponibilizado para outros usuarios.' : 'Modelo removido da lista compartilhada.',
            'model' => $updated ? $this->mapRow($updated, $user) : null,
        ]);
    }

    public function updateAccessUsers(Request $request, $modelo): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (($user->role ?? null) !== 'admin') {
            return response()->json(['message' => 'Apenas administradores podem gerenciar acesso aos modelos.'], 403);
        }

        if (!$this->hasAccessTable()) {
            return response()->json(['message' => 'Atualize o banco de dados para liberar modelos por usuario.'], 409);
        }

        $row = $this->getOwnedModelForUser($request, $modelo);
        if (!$row) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        $validated = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userIds = collect($validated['user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id !== (int) $user->id)
            ->unique()
            ->values();

        $activeUserIds = DB::table('users')
            ->whereIn('id', $userIds)
            ->where('is_active', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        DB::transaction(function () use ($modelo, $user, $activeUserIds) {
            DB::table('modelo_user_accesses')->where('modelo_id', $modelo)->delete();

            if ($activeUserIds->isEmpty()) {
                return;
            }

            $now = now();
            DB::table('modelo_user_accesses')->insert(
                $activeUserIds->map(fn ($userId) => [
                    'modelo_id' => (int) $modelo,
                    'user_id' => $userId,
                    'granted_by_user_id' => (int) $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        });

        $updated = DB::table('modelos')->where('id', $modelo)->first();

        return response()->json([
            'message' => 'Acesso dos usuarios atualizado.',
            'model' => $updated ? $this->mapRow($updated, $user) : null,
        ]);
    }

    public function updateBulkAccessUsers(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        if (($user->role ?? null) !== 'admin') {
            return response()->json(['message' => 'Apenas administradores podem gerenciar acesso aos modelos.'], 403);
        }

        if (!$this->hasAccessTable()) {
            return response()->json(['message' => 'Atualize o banco de dados para liberar modelos por usuario.'], 409);
        }

        $validated = $request->validate([
            'model_ids' => ['required', 'array', 'min:1'],
            'model_ids.*' => ['integer', 'exists:modelos,id'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $modelIds = collect($validated['model_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $ownedModelIds = DB::table('modelos')
            ->where('user_id', $user->id)
            ->whereIn('id', $modelIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($ownedModelIds->isEmpty() || $ownedModelIds->count() !== $modelIds->count()) {
            return response()->json(['message' => 'Um ou mais modelos nao pertencem ao usuario admin.'], 404);
        }

        $userIds = collect($validated['user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id !== (int) $user->id)
            ->unique()
            ->values();

        $activeUserIds = DB::table('users')
            ->whereIn('id', $userIds)
            ->where('is_active', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        DB::transaction(function () use ($ownedModelIds, $user, $activeUserIds) {
            DB::table('modelo_user_accesses')->whereIn('modelo_id', $ownedModelIds)->delete();

            if ($activeUserIds->isEmpty()) {
                return;
            }

            $now = now();
            $rows = [];
            foreach ($ownedModelIds as $modelId) {
                foreach ($activeUserIds as $userId) {
                    $rows[] = [
                        'modelo_id' => (int) $modelId,
                        'user_id' => (int) $userId,
                        'granted_by_user_id' => (int) $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('modelo_user_accesses')->insert($rows);
        });

        $models = DB::table('modelos')
            ->whereIn('id', $ownedModelIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => $this->mapRow($row, $user))
            ->values();

        return response()->json([
            'message' => 'Acesso dos modelos selecionados atualizado.',
            'models' => $models,
        ]);
    }

    public function destroy(Request $request, $modelo): JsonResponse
    {
        $row = $this->getOwnedModelForUser($request, $modelo);
        if (!$row) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        if ($row->pdf_path) {
            Storage::disk('public')->delete(preg_replace('#^public/#', '', $row->pdf_path));
        }
        if ($row->verso_path ?? null) {
            Storage::disk('public')->delete(preg_replace('#^public/#', '', $row->verso_path));
        }

        DB::table('modelos')->where('id', $modelo)->delete();

        return response()->json(['message' => 'Modelo excluido com sucesso.']);
    }

    private function getVisibleModelForUser(Request $request, $modelo): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('modelos')
            ->where('id', $modelo)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id);

                if ($this->hasSharedColumns()) {
                    $query->orWhere('is_shared', true);
                }

                if ($this->hasAccessTable()) {
                    $query->orWhereExists(function ($subQuery) use ($user) {
                        $subQuery->selectRaw('1')
                            ->from('modelo_user_accesses')
                            ->whereColumn('modelo_user_accesses.modelo_id', 'modelos.id')
                            ->where('modelo_user_accesses.user_id', $user->id);
                    });
                }
            })
            ->first();
    }

    private function getOwnedModelForUser(Request $request, $modelo): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('modelos')
            ->where('id', $modelo)
            ->where('user_id', $user->id)
            ->first();
    }

    private function mapRow(object $row, ?object $user = null): array
    {
        $isOwner = $user ? ((int) $row->user_id === (int) $user->id) : false;
        $isShared = property_exists($row, 'is_shared') ? (bool) $row->is_shared : false;
        $isAdmin = $user && (($user->role ?? null) === 'admin');
        $sharedUserIds = [];

        if ($isAdmin && $isOwner && $this->hasAccessTable()) {
            $sharedUserIds = DB::table('modelo_user_accesses')
                ->where('modelo_id', $row->id)
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();
        }

        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'name' => (string) $row->name,
            'sheet_size' => (string) $row->sheet_size,
            'orientation' => (string) $row->orientation,
            'pdf_name' => $row->pdf_name ? (string) $row->pdf_name : null,
            'pdf_url' => $row->pdf_path ? Storage::disk('public')->url(preg_replace("#^public/#","", $row->pdf_path)) : null,
            'verso_name' => ($row->verso_name ?? null) ? (string) $row->verso_name : null,
            'verso_url' => ($row->verso_path ?? null) ? Storage::disk('public')->url(preg_replace("#^public/#","", $row->verso_path)) : null,
            'editor_state' => $row->editor_state ? json_decode($row->editor_state, true) : null,
            'is_shared' => $isShared,
            'shared_at' => property_exists($row, 'shared_at') ? $row->shared_at : null,
            'is_owner' => $isOwner,
            'readonly' => !$isOwner,
            'can_share' => $isAdmin && $isOwner && $this->hasSharedColumns(),
            'can_manage_access' => $isAdmin && $isOwner && $this->hasAccessTable(),
            'shared_user_ids' => $sharedUserIds,
            'created_at' => $row->created_at,
        ];
    }

    private function hasSharedColumns(): bool
    {
        static $hasColumns = null;
        if ($hasColumns === null) {
            $hasColumns = Schema::hasColumn('modelos', 'is_shared') && Schema::hasColumn('modelos', 'shared_at');
        }

        return $hasColumns;
    }

    private function hasAccessTable(): bool
    {
        static $hasTable = null;
        if ($hasTable === null) {
            $hasTable = Schema::hasTable('modelo_user_accesses');
        }

        return $hasTable;
    }

    private function defaultEditorState(): array
    {
        return [
            'items' => [
                $this->defaultTextItem('nome', 'Nome', 'Nome aqui', 58, 72, 76, 40, '#111827', '#ffffff', 4),
                $this->defaultTextItem('idade', 'Idade', '8 anos', 46, 72, 84, 34, '#111827', '#ffffff', 3),
            ],
            'customFonts' => [],
            'zoom' => 1,
        ];
    }

    private function defaultTextItem(
        string $type,
        string $title,
        string $value,
        int $fontSize,
        int $left,
        int $top,
        int $width,
        string $color,
        string $strokeColor,
        int $strokeWidth
    ): array {
        return [
            'id' => uniqid($type . '-', true),
            'type' => $type,
            'title' => $title,
            'value' => $value,
            'fontFamily' => 'Inter',
            'fontSize' => $fontSize,
            'color' => $color,
            'strokeColor' => $strokeColor,
            'strokeWidth' => $strokeWidth,
            'borderColor' => '#ffffff',
            'borderWidth' => 0,
            'borderRadius' => 18,
            'left' => $left,
            'top' => $top,
            'width' => $width,
            'align' => 'center',
        ];
    }
}







