<?php

namespace App\Http\Controllers\Api;

use App\Models\PdfTranslationJob;
use App\Services\PdfTranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class PdfTranslationController
{
    public function __construct(private readonly PdfTranslationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $jobs = PdfTranslationJob::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PdfTranslationJob $job) => $this->mapJob($job))
            ->values();

        return response()->json(['jobs' => $jobs]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $file = $request->file('pdf');
        $path = $file->store('pdf-translations/originals/' . $user->id, 'public');

        $job = PdfTranslationJob::create([
            'user_id' => $user->id,
            'original_name' => $file->getClientOriginalName(),
            'original_path' => $path,
            'status' => 'pending',
            'source_language' => 'spanish',
            'target_language' => 'english',
        ]);

        $job = $this->service->process($job);

        return response()->json([
            'message' => $job->status === 'completed' ? 'PDF traduzido com sucesso.' : 'Falha ao traduzir PDF.',
            'job' => $this->mapJob($job),
        ], $job->status === 'completed' ? 201 : 422);
    }

    public function retry(Request $request, PdfTranslationJob $job): JsonResponse
    {
        $user = $request->user();
        if (!$user || (int) $job->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Arquivo nao encontrado.'], 404);
        }

        $job = $this->service->process($job);

        return response()->json([
            'message' => $job->status === 'completed' ? 'PDF traduzido com sucesso.' : 'Falha ao traduzir PDF.',
            'job' => $this->mapJob($job),
        ], $job->status === 'completed' ? 200 : 422);
    }

    public function download(Request $request, PdfTranslationJob $job, string $type)
    {
        $user = $request->user();
        if (!$user || (int) $job->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Arquivo nao encontrado.'], 404);
        }

        $path = $type === 'original' ? $job->original_path : $job->translated_path;
        if (!$path || !Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Arquivo nao encontrado.'], 404);
        }

        $name = $type === 'original'
            ? $job->original_name
            : preg_replace('/\.pdf$/i', '', $job->original_name) . '-traduzido.pdf';

        return Storage::disk('public')->download($path, $name);
    }

    public function destroy(Request $request, PdfTranslationJob $job): JsonResponse
    {
        $user = $request->user();
        if (!$user || (int) $job->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Arquivo nao encontrado.'], 404);
        }

        Storage::disk('public')->delete(array_filter([$job->original_path, $job->translated_path]));
        $job->delete();

        return response()->json(['message' => 'Historico removido.']);
    }

    private function mapJob(PdfTranslationJob $job): array
    {
        return [
            'id' => (string) $job->id,
            'original_name' => $job->original_name,
            'status' => $job->status,
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'page_count' => (int) $job->page_count,
            'spanish_blocks' => (int) $job->spanish_blocks,
            'error_message' => $job->error_message,
            'created_at' => optional($job->created_at)->toISOString(),
            'processed_at' => optional($job->processed_at)->toISOString(),
            'has_translated_file' => (bool) $job->translated_path,
        ];
    }
}
