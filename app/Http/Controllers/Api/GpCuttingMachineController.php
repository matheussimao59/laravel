<?php

namespace App\Http\Controllers\Api;

use App\Models\GpCuttingMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GpCuttingMachineController
{
    protected function ensureAtLeastOneDefault(GpCuttingMachine $machine): void
    {
        $id = $machine->id;
        $anyDefault = GpCuttingMachine::where('is_default', true)
            ->where('user_id', $machine->user_id)
            ->where('id', '!=', $id)
            ->exists();
        if (!$anyDefault) {
            $machine->is_default = true;
            $machine->saveQuietly();
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }
        $machines = GpCuttingMachine::where('user_id', $user->id)->orderByDesc('is_default')->orderBy('name')->get();
        return response()->json(['machines' => $machines]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'sheet_width' => ['nullable', 'numeric', 'min:0'],
            'sheet_height' => ['nullable', 'numeric', 'min:0'],
            'usable_width' => ['nullable', 'numeric', 'min:0'],
            'usable_height' => ['nullable', 'numeric', 'min:0'],
            'spacing' => ['nullable', 'numeric', 'min:0'],
            'margin' => ['nullable', 'numeric', 'min:0'],
            'default_sheet' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        $wantsDefault = $request->boolean('is_default', false);
        if ($wantsDefault) {
            GpCuttingMachine::where('user_id', $user->id)->update(['is_default' => false]);
        }
        if (!GpCuttingMachine::where('user_id', $user->id)->exists()) {
            $wantsDefault = true;
        }

        $machine = GpCuttingMachine::create([
            'user_id' => $user->id,
            'name' => trim($request->input('name')),
            'manufacturer' => $request->input('manufacturer'),
            'model' => $request->input('model'),
            'sheet_width' => $request->input('sheet_width', 0),
            'sheet_height' => $request->input('sheet_height', 0),
            'usable_width' => $request->input('usable_width', 0),
            'usable_height' => $request->input('usable_height', 0),
            'spacing' => $request->input('spacing', 0),
            'margin' => $request->input('margin', 0),
            'default_sheet' => $request->input('default_sheet', 'a3'),
            'notes' => $request->input('notes'),
            'is_default' => $wantsDefault,
        ]);

        return response()->json(['message' => 'Maquina criada com sucesso.', 'machine' => $machine], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }
        $machine = GpCuttingMachine::where('id', $id)->where('user_id', $user->id)->first();
        if (!$machine) {
            return response()->json(['message' => 'Maquina nao encontrada.'], 404);
        }
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'sheet_width' => ['nullable', 'numeric', 'min:0'],
            'sheet_height' => ['nullable', 'numeric', 'min:0'],
            'usable_width' => ['nullable', 'numeric', 'min:0'],
            'usable_height' => ['nullable', 'numeric', 'min:0'],
            'spacing' => ['nullable', 'numeric', 'min:0'],
            'margin' => ['nullable', 'numeric', 'min:0'],
            'default_sheet' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Dados invalidos.', 'errors' => $validator->errors()], 422);
        }

        if ($request->has('is_default') && $request->boolean('is_default')) {
            GpCuttingMachine::where('user_id', $user->id)
                ->where('id', '!=', $machine->id)
                ->update(['is_default' => false]);
        }

        $machine->update($request->only([
            'name', 'manufacturer', 'model', 'sheet_width', 'sheet_height',
            'usable_width', 'usable_height', 'spacing', 'margin', 'default_sheet', 'notes', 'is_default',
        ]));

        if ($request->has('is_default') && !$request->boolean('is_default')) {
            $this->ensureAtLeastOneDefault($machine);
        }

        return response()->json(['message' => 'Maquina atualizada com sucesso.', 'machine' => $machine]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario nao autenticado.'], 401);
        }
        $machine = GpCuttingMachine::where('id', $id)->where('user_id', $user->id)->first();
        if (!$machine) {
            return response()->json(['message' => 'Maquina nao encontrada.'], 404);
        }

        DB::transaction(function () use ($machine) {
            $wasDefault = $machine->is_default;
            $machine->delete();
            if ($wasDefault) {
                $next = GpCuttingMachine::where('user_id', $machine->user_id)->orderBy('id')->first();
                if ($next) {
                    $next->is_default = true;
                    $next->saveQuietly();
                }
            }
        });

        return response()->json(['message' => 'Maquina excluida com sucesso.'], 204);
    }
}