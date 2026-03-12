<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class FiscalDocumentController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = DB::table('fiscal_documents')->where('user_id', $user->id)->orderByDesc('created_at');
        if ($request->filled('order_id')) {
            $query->where('order_id', (int) $request->input('order_id'));
        }

        return response()->json([
            'documents' => $query->limit((int) $request->input('limit', 300))->get()->map(fn ($row) => $this->mapRow($row))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'integer'],
            'status' => ['nullable', 'string', 'max:60'],
            'invoice_number' => ['nullable', 'string', 'max:60'],
            'invoice_series' => ['nullable', 'string', 'max:20'],
            'access_key' => ['nullable', 'string', 'max:80'],
            'provider_ref' => ['nullable', 'string', 'max:255'],
            'xml_url' => ['nullable', 'string', 'max:255'],
            'pdf_url' => ['nullable', 'string', 'max:255'],
            'error_message' => ['nullable', 'string'],
            'issued_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para documento fiscal.', 'errors' => $validator->errors()], 422);
        }

        $orderId = (int) $request->input('order_id');
        $existing = DB::table('fiscal_documents')->where('user_id', $user->id)->where('order_id', $orderId)->first();
        $payload = [
            'status' => (string) $request->input('status', 'pending'),
            'invoice_number' => $request->input('invoice_number'),
            'invoice_series' => $request->input('invoice_series'),
            'access_key' => $request->input('access_key'),
            'provider_ref' => $request->input('provider_ref'),
            'xml_url' => $request->input('xml_url'),
            'pdf_url' => $request->input('pdf_url'),
            'error_message' => $request->input('error_message'),
            'issued_at' => $request->input('issued_at'),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('fiscal_documents')->where('id', $existing->id)->update($payload);
            $row = DB::table('fiscal_documents')->where('id', $existing->id)->first();

            return response()->json([
                'message' => 'Documento fiscal atualizado com sucesso.',
                'document' => $row ? $this->mapRow($row) : null,
            ]);
        }

        $id = DB::table('fiscal_documents')->insertGetId([
            'user_id' => $user->id,
            'order_id' => $orderId,
            ...$payload,
            'created_at' => now(),
        ]);

        $row = DB::table('fiscal_documents')->where('id', $id)->first();

        return response()->json([
            'message' => 'Documento fiscal criado com sucesso.',
            'document' => $row ? $this->mapRow($row) : null,
        ], 201);
    }

    public function update(Request $request, string $document): JsonResponse
    {
        $row = $this->findOwnedRow($request, $document);
        if (!$row) {
            return response()->json(['message' => 'Documento fiscal nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'string', 'max:60'],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'invoice_series' => ['sometimes', 'nullable', 'string', 'max:20'],
            'access_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'provider_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'xml_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pdf_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'error_message' => ['sometimes', 'nullable', 'string'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para documento fiscal.', 'errors' => $validator->errors()], 422);
        }

        DB::table('fiscal_documents')->where('id', (int) $document)->update([
            'status' => $request->input('status', $row->status),
            'invoice_number' => $request->input('invoice_number', $row->invoice_number),
            'invoice_series' => $request->input('invoice_series', $row->invoice_series),
            'access_key' => $request->input('access_key', $row->access_key),
            'provider_ref' => $request->input('provider_ref', $row->provider_ref),
            'xml_url' => $request->input('xml_url', $row->xml_url),
            'pdf_url' => $request->input('pdf_url', $row->pdf_url),
            'error_message' => $request->input('error_message', $row->error_message),
            'issued_at' => $request->exists('issued_at') ? $request->input('issued_at') : $row->issued_at,
            'updated_at' => now(),
        ]);

        $updated = DB::table('fiscal_documents')->where('id', (int) $document)->first();

        return response()->json([
            'message' => 'Documento fiscal atualizado com sucesso.',
            'document' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $document): JsonResponse
    {
        $row = $this->findOwnedRow($request, $document);
        if (!$row) {
            return response()->json(['message' => 'Documento fiscal nao encontrado.'], 404);
        }

        DB::table('fiscal_documents')->where('id', (int) $document)->delete();

        return response()->json(['message' => 'Documento fiscal excluido com sucesso.', 'id' => $document]);
    }

    private function findOwnedRow(Request $request, string $document): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('fiscal_documents')->where('id', (int) $document)->where('user_id', $user->id)->first();
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'order_id' => (int) $row->order_id,
            'status' => $row->status,
            'invoice_number' => $row->invoice_number,
            'invoice_series' => $row->invoice_series,
            'access_key' => $row->access_key,
            'provider_ref' => $row->provider_ref,
            'xml_url' => $row->xml_url,
            'pdf_url' => $row->pdf_url,
            'error_message' => $row->error_message,
            'issued_at' => $row->issued_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
