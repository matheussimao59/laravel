<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class MediaController
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'application/pdf',
    ];

    public function file(Request $request): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $file = (string) $request->query('file', '');
        $file = str_replace('\\', '/', trim($file));
        if ($file === '' || str_contains($file, '..')) {
            return response()->json(['message' => 'Arquivo invalido.'], 422);
        }

        $file = preg_replace('#^(public/|storage/|/+)#', '', $file);
        if ($file === '' || $file === '/' || $file === 'storage') {
            return response()->json(['message' => 'Arquivo invalido.'], 422);
        }

        if (!Storage::disk('public')->exists($file)) {
            return response()->json(['message' => 'Arquivo nao encontrado.'], 404);
        }

        $mime = (string) Storage::disk('public')->mimeType($file);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return response()->json(['message' => 'Formato de arquivo nao suportado.'], 422);
        }

        return response()->download(
            Storage::disk('public')->path($file),
            basename($file),
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}