<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CalendarOrderController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $query = DB::table('calendar_orders')->where('user_id', $user->id)->orderByDesc('created_at');
        if ($request->has('printed')) {
            $query->where('printed', $request->boolean('printed'));
        }

        return response()->json([
            'orders' => $query->get()->map(fn ($row) => $this->mapRow($row))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'string', 'max:120'],
            'image_data' => ['required', 'string'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'printed' => ['nullable', 'boolean'],
            'printed_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para calendario.', 'errors' => $validator->errors()], 422);
        }

        $id = DB::table('calendar_orders')->insertGetId([
            'user_id' => $user->id,
            'order_id' => trim((string) $request->input('order_id')),
            'image_data' => $request->input('image_data'),
            'quantity' => (int) $request->input('quantity', 1),
            'printed' => $request->boolean('printed', false),
            'printed_at' => $request->input('printed_at'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('calendar_orders')->where('id', $id)->first();

        return response()->json([
            'message' => 'Calendario salvo com sucesso.',
            'order' => $row ? $this->mapRow($row) : null,
        ], 201);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $row = $this->findOwnedRow($request, $order);
        if (!$row) {
            return response()->json(['message' => 'Calendario nao encontrado.'], 404);
        }

        return response()->json(['order' => $this->mapRow($row)]);
    }

    public function update(Request $request, string $order): JsonResponse
    {
        $row = $this->findOwnedRow($request, $order);
        if (!$row) {
            return response()->json(['message' => 'Calendario nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => ['sometimes', 'string', 'max:120'],
            'image_data' => ['sometimes', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'printed' => ['sometimes', 'boolean'],
            'printed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos para calendario.', 'errors' => $validator->errors()], 422);
        }

        DB::table('calendar_orders')->where('id', (int) $order)->update([
            'order_id' => $request->has('order_id') ? trim((string) $request->input('order_id')) : $row->order_id,
            'image_data' => $request->has('image_data') ? $request->input('image_data') : $row->image_data,
            'quantity' => $request->has('quantity') ? (int) $request->input('quantity') : $row->quantity,
            'printed' => $request->has('printed') ? $request->boolean('printed') : (bool) $row->printed,
            'printed_at' => $request->exists('printed_at') ? $request->input('printed_at') : $row->printed_at,
            'updated_at' => now(),
        ]);

        $updated = DB::table('calendar_orders')->where('id', (int) $order)->first();

        return response()->json([
            'message' => 'Calendario atualizado com sucesso.',
            'order' => $updated ? $this->mapRow($updated) : null,
        ]);
    }

    public function destroy(Request $request, string $order): JsonResponse
    {
        $row = $this->findOwnedRow($request, $order);
        if (!$row) {
            return response()->json(['message' => 'Calendario nao encontrado.'], 404);
        }

        DB::table('calendar_orders')->where('id', (int) $order)->delete();

        return response()->json(['message' => 'Calendario excluido com sucesso.', 'id' => $order]);
    }

    private function findOwnedRow(Request $request, string $order): ?object
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return DB::table('calendar_orders')->where('id', (int) $order)->where('user_id', $user->id)->first();
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'order_id' => $row->order_id,
            'image_data' => $row->image_data,
            'printed' => (bool) $row->printed,
            'quantity' => (int) ($row->quantity ?? 1),
            'printed_at' => $row->printed_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
