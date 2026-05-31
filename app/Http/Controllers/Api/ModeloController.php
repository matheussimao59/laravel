<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ModeloController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $models = DB::table('modelos')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
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
            'editor_state' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('modelos')->where('id', $id)->first();

        return response()->json([
            'message' => 'Modelo criado com sucesso.',
            'model' => $row ? $this->mapRow($row) : null,
        ]);
    }

    public function show(Request $request, $modelo): JsonResponse
    {
        $row = $this->getModelForUser($request, $modelo);
        if (!$row) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        return response()->json(['model' => $this->mapRow($row)]);
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

        $row = $this->getModelForUser($request, $modelo);
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
            'model' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, $modelo): JsonResponse
    {
        $row = $this->getModelForUser($request, $modelo);
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

    private function getModelForUser(Request $request, $modelo)
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('modelos')
            ->where('user_id', $user->id)
            ->where('id', $modelo)
            ->first();
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'sheet_size' => (string) $row->sheet_size,
            'orientation' => (string) $row->orientation,
            'pdf_name' => $row->pdf_name ? (string) $row->pdf_name : null,
            'pdf_url' => $row->pdf_path ? Storage::disk('public')->url(preg_replace("#^public/#","", $row->pdf_path)) : null,
            'verso_name' => ($row->verso_name ?? null) ? (string) $row->verso_name : null,
            'verso_url' => ($row->verso_path ?? null) ? Storage::disk('public')->url(preg_replace("#^public/#","", $row->verso_path)) : null,
            'editor_state' => $row->editor_state ? json_decode($row->editor_state, true) : null,
            'created_at' => $row->created_at,
        ];
    }
}







