<?php

namespace App\Http\Controllers\Api;

use App\Models\GpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GpClientController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $clients = GpClient::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['clients' => $clients]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $client = GpClient::create([
            'user_id' => $user->id,
            'name' => trim($request->input('name')),
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            'address' => $request->input('address'),
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Cliente criado com sucesso.', 'client' => $client], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $client = GpClient::where('id', $id)->where('user_id', $user->id)->first();
        if (!$client) {
            return response()->json(['message' => 'Cliente nao encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $client->update($request->only(['name', 'phone', 'email', 'address', 'notes']));

        return response()->json(['message' => 'Cliente atualizado com sucesso.', 'client' => $client]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }

        $client = GpClient::where('id', $id)->where('user_id', $user->id)->first();
        if (!$client) {
            return response()->json(['message' => 'Cliente nao encontrado.'], 404);
        }

        $client->delete();

        return response()->json(['message' => 'Cliente excluido com sucesso.'], 204);
    }
}
