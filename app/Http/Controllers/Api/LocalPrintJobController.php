<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class LocalPrintJobController
{
    private const SETTING_ID = 'local_print_agent_config';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $limit = max(1, min(200, (int) $request->input('limit', 80)));
        $rows = DB::table('local_print_jobs')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'jobs' => $rows->map(fn ($row) => $this->mapJob($row, false))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'manual_print_order_id' => ['nullable', 'integer', 'exists:manual_print_orders,id'],
            'printer_name' => ['nullable', 'string', 'max:180'],
            'page_order' => ['nullable', 'string', 'in:normal,reverse'],
            'print_profile' => ['nullable', 'array'],
            'print_profile.id' => ['nullable', 'string'],
            'print_profile.name' => ['nullable', 'string'],
            'print_profile.printerName' => ['nullable', 'string'],
            'print_profile.paperType' => ['nullable', 'string'],
            'print_profile.paperSize' => ['nullable', 'string'],
            'print_profile.quality' => ['nullable', 'string'],
            'print_profile.colorMode' => ['nullable', 'string'],
            'print_profile.borderMode' => ['nullable', 'string'],
            'print_profile.driverProfileCapturedAt' => ['nullable', 'string'],
            'print_profile.driverProfileStatus' => ['nullable', 'string'],
            'print_profile.driverProfileMessage' => ['nullable', 'string'],
            'print_profile.notes' => ['nullable', 'string'],
            'print_side' => ['nullable', 'string', 'in:front,back'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'document_html' => ['required', 'string'],
        ], [
            'max.string' => 'O campo :attribute ultrapassou o tamanho permitido.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para fila de impressao.', 'errors' => $validator->errors()], 422);
        }

        $orderId = $request->input('manual_print_order_id');
        if ($orderId && !$this->orderBelongsToUser((int) $orderId, (int) $user->id)) {
            return response()->json(['message' => 'Pedido de impressao nao encontrado.'], 404);
        }

        $printSide = $request->input('print_side', 'front') === 'back' ? 'back' : 'front';
        if ($orderId && $this->hasPrintSideColumn()) {
            DB::table('local_print_jobs')
                ->where('user_id', $user->id)
                ->where('manual_print_order_id', (int) $orderId)
                ->where('print_side', $printSide)
                ->whereIn('status', ['pending', 'failed'])
                ->delete();
        }

        $insertData = [
            'user_id' => $user->id,
            'manual_print_order_id' => $orderId ? (int) $orderId : null,
            'printer_name' => $request->filled('printer_name') ? trim((string) $request->input('printer_name')) : null,
            'page_order' => $request->input('page_order', 'normal'),
            'print_profile' => $request->has('print_profile') ? json_encode($request->input('print_profile')) : null,
            'copies' => (int) $request->input('copies', 1),
            'status' => 'pending',
            'document_html' => $request->input('document_html'),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($this->hasPrintSideColumn()) {
            $insertData['print_side'] = $printSide;
        }

        $jobId = DB::table('local_print_jobs')->insertGetId($insertData);

        $row = DB::table('local_print_jobs')->where('id', $jobId)->first();

        return response()->json([
            'message' => 'Trabalho enviado para o agente de impressao.',
            'job' => $this->mapJob($row, false),
        ], 201);
    }

    public function next(Request $request): JsonResponse
    {
        $userId = $this->userIdFromAgentToken((string) $request->bearerToken());
        if (!$userId) {
            return response()->json(['message' => 'Token do agente invalido.'], 401);
        }

        $job = DB::transaction(function () use ($userId) {
            $staleBefore = now()->subMinutes(3)->toDateTimeString();
            $processingJob = DB::table('local_print_jobs')
                ->where('user_id', $userId)
                ->where('status', 'processing')
                ->orderBy('picked_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (
                $processingJob
                && (!$processingJob->picked_at || $processingJob->picked_at <= $staleBefore)
            ) {
                DB::table('local_print_jobs')
                    ->where('user_id', $userId)
                    ->where('status', 'processing')
                    ->where(function ($query) use ($staleBefore) {
                        $query->whereNull('picked_at')->orWhere('picked_at', '<=', $staleBefore);
                    })
                    ->update([
                        'status' => 'failed',
                        'error_message' => 'Trabalho liberado automaticamente porque o agente nao confirmou a impressao.',
                        'updated_at' => now(),
                    ]);

                $processingJob = DB::table('local_print_jobs')
                    ->where('user_id', $userId)
                    ->where('status', 'processing')
                    ->orderBy('picked_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
            }

            if ($processingJob) {
                return null;
            }

            $nextJob = DB::table('local_print_jobs')
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$nextJob) {
                return null;
            }

            DB::table('local_print_jobs')->where('id', $nextJob->id)->update([
                'status' => 'processing',
                'picked_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('local_print_jobs')->where('id', $nextJob->id)->first();
        });

        if (!$job) {
            return response()->json(['job' => null]);
        }

        $mappedJob = $this->mapJob($job, true);

        return response()->json([
            'job' => $mappedJob,
            'jobs' => [$mappedJob],
        ]);
    }

    public function complete(Request $request, string $job): JsonResponse
    {
        $userId = $this->userIdFromAgentToken((string) $request->bearerToken());
        if (!$userId) {
            return response()->json(['message' => 'Token do agente invalido.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:printed,failed'],
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para retorno do agente.', 'errors' => $validator->errors()], 422);
        }

        $row = DB::table('local_print_jobs')
            ->where('id', (int) $job)
            ->where('user_id', $userId)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Trabalho de impressao nao encontrado.'], 404);
        }

        $status = $request->input('status');
        DB::table('local_print_jobs')->where('id', (int) $job)->update([
            'status' => $status,
            'error_message' => $status === 'failed' ? $request->input('error_message') : null,
            'printed_at' => $status === 'printed' ? now() : null,
            'updated_at' => now(),
        ]);

        if ($status === 'printed' && $row->manual_print_order_id && !$this->hasUnfinishedJobsForOrder((int) $row->manual_print_order_id, $userId)) {
            DB::table('manual_print_orders')->where('id', $row->manual_print_order_id)->update([
                'status' => 'Impresso',
                'printed_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Retorno do agente registrado.']);
    }

    public function retry(Request $request, string $job): JsonResponse
    {
        $row = $this->jobForAuthenticatedUser($request, $job);
        if ($row instanceof JsonResponse) {
            return $row;
        }
        if (!in_array($row->status, ['failed', 'cancelled'], true)) {
            return response()->json(['message' => 'Somente trabalhos com falha ou cancelados podem ser reenviados.'], 422);
        }

        DB::table('local_print_jobs')->where('id', $row->id)->update([
            'status' => 'pending',
            'error_message' => null,
            'picked_at' => null,
            'printed_at' => null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Trabalho reenviado para a fila.',
            'job' => $this->mapJob(DB::table('local_print_jobs')->where('id', $row->id)->first(), false),
        ]);
    }

    public function cancel(Request $request, string $job): JsonResponse
    {
        $row = $this->jobForAuthenticatedUser($request, $job);
        if ($row instanceof JsonResponse) {
            return $row;
        }
        if (!in_array($row->status, ['pending', 'failed'], true)) {
            return response()->json(['message' => 'Este trabalho ja foi recebido pelo agente e nao pode ser cancelado aqui.'], 422);
        }

        DB::table('local_print_jobs')->where('id', $row->id)->update([
            'status' => 'cancelled',
            'error_message' => null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Trabalho cancelado.',
            'job' => $this->mapJob(DB::table('local_print_jobs')->where('id', $row->id)->first(), false),
        ]);
    }

    public function captureProfile(Request $request, string $profile): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'printer_name' => ['required', 'string', 'max:180'],
            'profile_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para captura do perfil.', 'errors' => $validator->errors()], 422);
        }

        $userId = (int) $user->id;
        $profileId = trim($profile);
        $printerName = trim((string) $request->input('printer_name'));
        $commandId = 'capture-' . $profileId . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4));
        $config = $this->agentConfigForUser($userId);

        $commands = array_values(array_filter(
            is_array($config['pendingCommands'] ?? null) ? $config['pendingCommands'] : [],
            fn ($command) => is_array($command)
                && (($command['status'] ?? 'pending') !== 'completed')
                && (($command['status'] ?? 'pending') !== 'failed')
                && !(
                    ($command['type'] ?? null) === 'capture_print_profile'
                    && ($command['profileId'] ?? null) === $profileId
                )
        ));

        $commands[] = [
            'id' => $commandId,
            'type' => 'capture_print_profile',
            'profileId' => $profileId,
            'profileName' => trim((string) $request->input('profile_name', $profileId)),
            'printerName' => $printerName,
            'status' => 'pending',
            'createdAt' => now()->toIso8601String(),
        ];

        $config['pendingCommands'] = $commands;
        $config['printProfiles'] = $this->markProfileCapturePending($config['printProfiles'] ?? [], $profileId);
        $this->saveAgentConfigForUser($userId, $config);

        return response()->json([
            'message' => 'Pedido de captura enviado para o agente. Ele abrira as Preferencias da impressora para escolher papel, qualidade e margem.',
            'command' => [
                'id' => $commandId,
                'type' => 'capture_print_profile',
                'profileId' => $profileId,
                'printerName' => $printerName,
            ],
        ], 202);
    }

    public function nextCommand(Request $request): JsonResponse
    {
        $userId = $this->userIdFromAgentToken((string) $request->bearerToken());
        if (!$userId) {
            return response()->json(['message' => 'Token do agente invalido.'], 401);
        }

        $config = $this->agentConfigForUser($userId);
        $commands = is_array($config['pendingCommands'] ?? null) ? $config['pendingCommands'] : [];
        $selected = null;

        foreach ($commands as $index => $command) {
            if (is_array($command) && (($command['status'] ?? 'pending') === 'pending')) {
                $commands[$index]['status'] = 'processing';
                $commands[$index]['pickedAt'] = now()->toIso8601String();
                $selected = $commands[$index];
                break;
            }
        }

        if (!$selected) {
            return response()->json(['command' => null]);
        }

        $config['pendingCommands'] = $commands;
        $this->saveAgentConfigForUser($userId, $config);

        return response()->json(['command' => $selected]);
    }

    public function completeCommand(Request $request, string $command): JsonResponse
    {
        $userId = $this->userIdFromAgentToken((string) $request->bearerToken());
        if (!$userId) {
            return response()->json(['message' => 'Token do agente invalido.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:completed,failed'],
            'profile_id' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:1000'],
            'captured_at' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para retorno do comando.', 'errors' => $validator->errors()], 422);
        }

        $config = $this->agentConfigForUser($userId);
        $commands = is_array($config['pendingCommands'] ?? null) ? $config['pendingCommands'] : [];
        $profileId = trim((string) $request->input('profile_id'));
        $status = (string) $request->input('status');
        $message = trim((string) $request->input('message', ''));

        foreach ($commands as $index => $row) {
            if (is_array($row) && (($row['id'] ?? null) === $command)) {
                $profileId = $profileId !== '' ? $profileId : (string) ($row['profileId'] ?? '');
                $commands[$index]['status'] = $status;
                $commands[$index]['message'] = $message;
                $commands[$index]['completedAt'] = now()->toIso8601String();
                break;
            }
        }

        $config['pendingCommands'] = array_values(array_filter(
            $commands,
            fn ($row) => is_array($row) && !in_array(($row['status'] ?? null), ['completed', 'failed'], true)
        ));

        if ($profileId !== '') {
            $config['printProfiles'] = $this->markProfileCaptureCompleted(
                $config['printProfiles'] ?? [],
                $profileId,
                $status,
                $message,
                $request->input('captured_at') ?: now()->toIso8601String(),
            );
        }

        $this->saveAgentConfigForUser($userId, $config);

        return response()->json(['message' => 'Comando registrado.']);
    }

    public function syncPrinters(Request $request): JsonResponse
    {
        $userId = $this->userIdFromAgentToken((string) $request->bearerToken());
        if (!$userId) {
            return response()->json(['message' => 'Token do agente invalido.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'printers' => ['required', 'array'],
            'printers.*.name' => ['required', 'string', 'max:180'],
            'printers.*.is_default' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos das impressoras.', 'errors' => $validator->errors()], 422);
        }

        $printers = collect($request->input('printers', []))
            ->map(fn ($printer) => [
                'name' => trim((string) ($printer['name'] ?? '')),
                'isDefault' => (bool) ($printer['is_default'] ?? false),
            ])
            ->filter(fn ($printer) => $printer['name'] !== '')
            ->unique('name')
            ->values()
            ->all();

        $current = $this->agentConfigForUser($userId);
        $next = [
            ...$current,
            'enabled' => (bool) ($current['enabled'] ?? true),
            'agentToken' => (string) ($current['agentToken'] ?? $request->bearerToken()),
            'printers' => array_map(fn ($printer) => $printer['name'], $printers),
            'printerDetails' => $printers,
            'lastSeenAt' => now()->toIso8601String(),
        ];

        DB::table('app_settings')->updateOrInsert(
            ['id' => self::SETTING_ID, 'user_id' => $userId],
            [
                'config_data' => json_encode($next),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Impressoras sincronizadas.',
            'printers' => $printers,
        ]);
    }

    private function orderBelongsToUser(int $orderId, int $userId): bool
    {
        return DB::table('manual_print_orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function jobForAuthenticatedUser(Request $request, string $job): object
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $row = DB::table('local_print_jobs')
            ->where('id', (int) $job)
            ->where('user_id', $user->id)
            ->first();

        return $row ?: response()->json(['message' => 'Trabalho de impressao nao encontrado.'], 404);
    }

    private function hasUnfinishedJobsForOrder(int $orderId, int $userId): bool
    {
        return DB::table('local_print_jobs')
            ->where('manual_print_order_id', $orderId)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing', 'failed'])
            ->exists();
    }

    private function userIdFromAgentToken(string $token): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $settings = DB::table('app_settings')
            ->where('id', self::SETTING_ID)
            ->whereNotNull('user_id')
            ->get(['user_id', 'config_data']);

        foreach ($settings as $setting) {
            $config = json_decode((string) $setting->config_data, true);
            if (is_array($config) && hash_equals((string) ($config['agentToken'] ?? ''), $token)) {
                return (int) $setting->user_id;
            }
        }

        return null;
    }

    private function agentConfigForUser(int $userId): array
    {
        $row = DB::table('app_settings')
            ->where('id', self::SETTING_ID)
            ->where('user_id', $userId)
            ->first();

        if (!$row) {
            return [];
        }

        $config = json_decode((string) $row->config_data, true);
        return is_array($config) ? $config : [];
    }

    private function saveAgentConfigForUser(int $userId, array $config): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['id' => self::SETTING_ID, 'user_id' => $userId],
            [
                'config_data' => json_encode($config),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function markProfileCapturePending(mixed $profiles, string $profileId): array
    {
        $rows = is_array($profiles) ? $profiles : [];
        foreach ($rows as $index => $profile) {
            if (is_array($profile) && (string) ($profile['id'] ?? '') === $profileId) {
                $rows[$index]['driverProfileStatus'] = 'pending';
                $rows[$index]['driverProfileMessage'] = 'Aguardando agente capturar as preferencias do driver.';
            }
        }

        return $rows;
    }

    private function markProfileCaptureCompleted(mixed $profiles, string $profileId, string $status, string $message, string $capturedAt): array
    {
        $rows = is_array($profiles) ? $profiles : [];
        foreach ($rows as $index => $profile) {
            if (is_array($profile) && (string) ($profile['id'] ?? '') === $profileId) {
                $rows[$index]['driverProfileStatus'] = $status === 'completed' ? 'captured' : 'failed';
                $rows[$index]['driverProfileMessage'] = $message;
                if ($status === 'completed') {
                    $rows[$index]['driverProfileCapturedAt'] = $capturedAt;
                }
            }
        }

        return $rows;
    }

    private function mapJob(?object $row, bool $includeDocument): ?array
    {
        if (!$row) {
            return null;
        }

        $payload = [
            'id' => (string) $row->id,
            'manualPrintOrderId' => $row->manual_print_order_id ? (string) $row->manual_print_order_id : null,
            'printerName' => $row->printer_name,
            'pageOrder' => $row->page_order,
            'printProfile' => $this->decodePrintProfile($row->print_profile ?? null),
            'printSide' => isset($row->print_side) && $row->print_side ? (string) $row->print_side : 'front',
            'copies' => (int) $row->copies,
            'status' => $row->status,
            'errorMessage' => $row->error_message,
            'pickedAt' => $row->picked_at,
            'printedAt' => $row->printed_at,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];

        if ($includeDocument) {
            $payload['documentHtml'] = $row->document_html;
        }

        return $payload;
    }

    private function decodePrintProfile(mixed $profile): ?array
    {
        if (!$profile) {
            return null;
        }

        $decoded = json_decode((string) $profile, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function hasPrintSideColumn(): bool
    {
        static $hasColumn = null;
        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('local_print_jobs', 'print_side');
        }

        return $hasColumn;
    }
}
