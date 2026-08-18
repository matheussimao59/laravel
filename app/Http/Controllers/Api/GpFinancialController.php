<?php

namespace App\Http\Controllers\Api;

use App\Models\GpCashFlow;
use App\Models\GpBill;
use App\Models\GpExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpFinancialController
{
    // ── Cash Flow ────────────────────────────────────────────

    public function indexCashFlow(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $items = GpCashFlow::where('user_id', $user->id)
            ->orderByDesc('date')
            ->get();

        return response()->json(['cash_flow' => $items]);
    }

    public function storeCashFlow(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric'],
            'category' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $item = GpCashFlow::create([
            'user_id' => $user->id,
            'type' => $request->input('type'),
            'description' => trim($request->input('description')),
            'amount' => $request->input('amount'),
            'category' => $request->input('category'),
            'date' => $request->input('date'),
        ]);

        return response()->json(['message' => 'Fluxo de caixa criado com sucesso.', 'cash_flow' => $item], 201);
    }

    public function destroyCashFlow(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $item = GpCashFlow::where('id', $id)->where('user_id', $user->id)->first();
        if (!$item) {
            return response()->json(['message' => 'Fluxo de caixa nao encontrado.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Fluxo de caixa excluido com sucesso.'], 204);
    }

    // ── Bills ───────────────────────────────────────────────

    public function indexBills(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $items = GpBill::where('user_id', $user->id)
            ->orderByDesc('due_date')
            ->get();

        return response()->json(['bills' => $items]);
    }

    public function storeBill(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'due_date' => ['required', 'date'],
            'paid' => ['nullable', 'boolean'],
            'paid_date' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $item = GpBill::create([
            'user_id' => $user->id,
            'description' => trim($request->input('description')),
            'amount' => $request->input('amount'),
            'due_date' => $request->input('due_date'),
            'paid' => $request->boolean('paid', false),
            'paid_date' => $request->input('paid_date'),
            'category' => $request->input('category'),
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Conta criada com sucesso.', 'bill' => $item], 201);
    }

    public function updateBill(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $item = GpBill::where('id', $id)->where('user_id', $user->id)->first();
        if (!$item) {
            return response()->json(['message' => 'Conta nao encontrada.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'description' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'due_date' => ['sometimes', 'date'],
            'paid' => ['sometimes', 'boolean'],
            'paid_date' => ['sometimes', 'nullable', 'date'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $item->update($request->only([
            'description', 'amount', 'due_date', 'paid', 'paid_date', 'category', 'notes',
        ]));

        return response()->json(['message' => 'Conta atualizada com sucesso.', 'bill' => $item]);
    }

    public function destroyBill(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $item = GpBill::where('id', $id)->where('user_id', $user->id)->first();
        if (!$item) {
            return response()->json(['message' => 'Conta nao encontrada.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Conta excluida com sucesso.'], 204);
    }

    // ── Expenses ────────────────────────────────────────────

    public function indexExpenses(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $items = GpExpense::where('user_id', $user->id)
            ->orderByDesc('date')
            ->get();

        return response()->json(['expenses' => $items]);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'type' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'recurring' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $item = GpExpense::create([
            'user_id' => $user->id,
            'description' => trim($request->input('description')),
            'amount' => $request->input('amount'),
            'type' => $request->input('type'),
            'category' => $request->input('category'),
            'date' => $request->input('date'),
            'recurring' => $request->boolean('recurring', false),
        ]);

        return response()->json(['message' => 'Despesa criada com sucesso.', 'expense' => $item], 201);
    }

    public function destroyExpense(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $item = GpExpense::where('id', $id)->where('user_id', $user->id)->first();
        if (!$item) {
            return response()->json(['message' => 'Despesa nao encontrada.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Despesa excluida com sucesso.'], 204);
    }
}
