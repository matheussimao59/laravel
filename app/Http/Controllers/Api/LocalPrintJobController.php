<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class LocalPrintJobController
{
    private const SETTING_ID = 'local_print_agent_config';

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
            'copies' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'document_html' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para fila de impressao.', 'errors' => $validator->errors()], 422);
        }

        $orderId = $request->input('manual_print_order_id');
        if ($orderId && !$this->orderBelongsToUser((int) $orderId, (int) $user->id)) {
            return response()->json(['message' => 'Pedido de impressao nao encontrado.'], 404);
        }

        $jobId = DB::table('local_print_jobs')->insertGetId([
            'user_id' => $user->id,
            'manual_print_order_id' => $orderId ? (int) $orderId : null,
            'printer_name' => $request->filled('printer_name') ? trim((string) $request->input('printer_name')) : null,
            'page_order' => $request->input('page_order', 'normal'),
            'copies' => (int) $request->input('copies', 1),
            'status' => 'pending',
            'document_html' => $request->input('document_html'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        $job = DB::table('local_print_jobs')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->first();

        if (!$job) {
            return response()->json(['job' => null]);
        }

        DB::table('local_print_jobs')->where('id', $job->id)->update([
            'status' => 'processing',
            'picked_at' => now(),
            'updated_at' => now(),
        ]);

        $updated = DB::table('local_print_jobs')->where('id', $job->id)->first();

        return response()->json(['job' => $this->mapJob($updated, true)]);
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

        if ($status === 'printed' && $row->manual_print_order_id) {
            DB::table('manual_print_orders')->where('id', $row->manual_print_order_id)->update([
                'status' => 'Impresso',
                'printed_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Retorno do agente registrado.']);
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
}
