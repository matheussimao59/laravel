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
            'pdf_base' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/jpg,image/pjpeg', 'max:20480'],
        ]);

        $pdfPath = null;
        $pdfName = null;

        if ($request->hasFile('pdf_base')) {
            $pdfFile = $request->file('pdf_base');
            $pdfName = $pdfFile->getClientOriginalName();
            $pdfPath = $pdfFile->store('modelos', 'public');
        }

        $id = DB::table('modelos')->insertGetId([
            'user_id' => $user->id,
            'name' => trim($validated['name']),
            'sheet_size' => trim($validated['sheet_size']),
            'orientation' => trim($validated['orientation']),
            'pdf_name' => $pdfName,
            'pdf_path' => $pdfPath,
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
            'editor_state' => ['nullable', 'array'],
        ]);

        $row = $this->getModelForUser($request, $modelo);
        if (!$row) {
            return response()->json(['message' => 'Modelo nao encontrado.'], 404);
        }

        DB::table('modelos')
            ->where('id', $modelo)
            ->update([
                'editor_state' => $validated['editor_state'] ? json_encode($validated['editor_state']) : null,
                'updated_at' => now(),
            ]);

        $updated = DB::table('modelos')->where('id', $modelo)->first();

        return response()->json([
            'message' => 'Modelo atualizado com sucesso.',
            'model' => $updated ? $this->mapRow($updated) : null,
        ]);
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
            'editor_state' => $row->editor_state ? json_decode($row->editor_state, true) : null,
            'created_at' => $row->created_at,
        ];
    }
}







