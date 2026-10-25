<?php

namespace App\Http\Controllers\Api;

use App\Models\MlPendingOrder;
use App\Services\MercadoLivreService;
use App\Services\MlPendingOrderService;
use App\Support\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MlPendingOrderController
{
    public function __construct(private readonly MercadoLivreService $ml, private readonly MlPendingOrderService $service) {}

    private function sellerId(Request $request): int { return (int) $request->user()->mercadoLivreAccount()->value('seller_id'); }

    private function owned(Request $request, int $id): ?MlPendingOrder
    {
        return MlPendingOrder::where('user_id', $request->user()->id)->where('seller_id', $this->sellerId($request))->find($id);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = MlPendingOrder::where('user_id', $request->user()->id)->where('seller_id', $this->sellerId($request))
            ->where(fn ($query) => $query->where('is_pending', true)->orWhereNull('is_pending'))->orderByDesc('ordered_at')->get();

        return response()->json(['orders' => $rows->map(fn ($row) => [...$row->toArray(), 'ready' => $this->service->ready($row)])->values()]);
    }

    public function syncPage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['offset' => ['nullable', 'integer', 'min:0', 'max:9990']]);
        if ($validator->fails()) return response()->json(['message' => 'Pagina invalida.', 'errors' => $validator->errors()], 422);
        try {
            $page = $this->ml->pendingOrdersPage($request->user(), (int) $request->input('offset', 0));
            foreach ($page['orders'] as $snapshot) $this->service->storeSnapshot($request->user(), ['seller_id' => $page['seller_id'], ...$snapshot]);
            unset($page['orders']);

            return response()->json($page);
        } catch (ExternalServiceException|ConnectionException $exception) { return $this->failure($exception); }
    }

    public function analyze(Request $request, int $id): JsonResponse
    {
        $row = $this->owned($request, $id);
        if (!$row) return response()->json(['message' => 'Pedido nao encontrado nesta conta.'], 404);
        try {
            return response()->json($this->service->analyze($request->user(), $row));
        } catch (ExternalServiceException|ConnectionException $exception) {
            $query = $this->service->purchase($request->user(), $row);
            (clone $query)->update(['messages_complete' => false, 'messages_checked_at' => now()]);
            $query->where('personalization_source', 'auto')->update(['personalization_status' => 'unavailable', 'analysis_note' => 'Leitura indisponivel ou incompleta. Nenhum preenchimento automatico foi autorizado.']);
            return $this->failure($exception);
        }
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        $row = $this->owned($request, $id);
        if (!$row) return response()->json(['message' => 'Pedido nao encontrado nesta conta.'], 404);
        try {
            return response()->json($this->ml->personalizationConversation($request->user(), $row->order_id, $row->pack_id, (int) $row->buyer_id));
        } catch (ExternalServiceException|ConnectionException $exception) { return $this->failure($exception); }
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $row = $this->owned($request, $id);
        if (!$row) return response()->json(['message' => 'Pedido nao encontrado nesta conta.'], 404);
        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:100'], 'age' => ['nullable', 'string', 'regex:/^\d{1,3}\s+(?:anos?|mes|meses)$/u'],
            'note' => ['nullable', 'string', 'max:2000'], 'confirmed' => ['required', 'boolean'], 'apply_to_all_items' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Confira nome, idade (ex.: 5 anos) e observacao.', 'errors' => $validator->errors()], 422);
        $name = trim((string) $request->input('name')); $age = trim((string) $request->input('age'));
        $confirmed = $request->boolean('confirmed');
        $shared = count($row->items ?? []) > 1 || ($row->pack_id && $row->pack_size !== null && $row->pack_size !== 1);
        if ($confirmed && ($name === '' || $age === '' || ($shared && !$request->boolean('apply_to_all_items')))) {
            return response()->json(['message' => 'Informe nome e idade e confirme a aplicacao aos itens deste pedido. Caso contrario, salve apenas como revisao.'], 422);
        }
        $note = trim((string) $request->input('note'));
        DB::transaction(function () use ($row, $name, $age, $note, $confirmed, $request) {
            $locked = MlPendingOrder::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'personalization_name' => $name ?: null, 'personalization_age' => $age ?: null,
                'personalization_pairs' => null,
                'personalization_status' => $confirmed ? 'confirmed' : 'review', 'personalization_source' => 'manual',
                'group_confirmed' => $request->boolean('apply_to_all_items'), 'manual_note' => $note ?: null,
                'observation' => ($confirmed ? "Nome: {$name} | Idade: {$age}" : 'Personalizacao em revisao; nao preencher automaticamente.') . ($note !== '' ? "\nObservacao: {$note}" : ''),
                'analysis_note' => 'Revisao manual preservada; Mila nao substitui estes dados.',
            ]);
        });

        return response()->json(['message' => $confirmed ? 'Dados confirmados para a importacao de impressao.' : 'Revisao salva sem autorizar preenchimento automatico.']);
    }

    public function lookup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['required', 'string', 'regex:/^\d{1,25}$/']]);
        if ($validator->fails()) return response()->json(['message' => 'Lista de pedidos invalida.', 'errors' => $validator->errors()], 422);

        return response()->json(['personalizations' => (object) $this->service->printLookup($request->user(), $this->sellerId($request), $request->input('ids'))]);
    }

    private function failure(\Throwable $exception): JsonResponse
    {
        if ($exception instanceof ConnectionException) {
            return response()->json([
                'message' => 'O Mercado Livre nao respondeu a tempo. Nenhuma leitura parcial foi guardada; tente novamente em alguns minutos.',
            ], 422);
        }

        if (!$exception instanceof ExternalServiceException) {
            report($exception);

            return response()->json([
                'message' => 'Nao foi possivel concluir a consulta. Verifique a conta e as permissoes; dados parciais nao autorizam preenchimento automatico.',
            ], 422);
        }

        if ($exception->status() === 429) {
            return response()->json([
                'message' => 'O Mercado Livre limitou as consultas. Aguarde alguns segundos antes de continuar.',
            ], 429);
        }

        // O front desloga o usuario em qualquer 401: falhas externas voltam como
        // 422 com orientacao de refazer o vinculo da conta.
        $metadata = json_decode($exception->getMessage(), true);
        if (!is_array($metadata)) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $code = (int) ($metadata['status'] ?? $exception->status());
        $details = $metadata['details'] ?? null;
        $detail = is_array($details)
            ? (string) ($details['message'] ?? $details['description'] ?? $details['error'] ?? '')
            : trim((string) ($details ?? ''));
        $detail = trim($detail);

        $hint = match (true) {
            $code === 401 => 'A conta do Mercado Livre foi desconectada ou o token expirou. Reconecte a conta no Painel Mercado Livre.',
            $code === 403 => 'A conta nao tem permissao para este recurso. Refaca a autorizacao no Painel Mercado Livre para liberar pedidos, envios e mensagens.',
            $code === 404 => 'Este pedido ou conversa nao esta mais disponivel no Mercado Livre.',
            default => 'O Mercado Livre recusou a consulta.',
        };

        return response()->json([
            'message' => $hint . ($detail !== '' ? " Resposta da API: {$detail}." : ''),
            'reason' => ['status' => $code, 'path' => (string) ($metadata['path'] ?? '')],
        ], 422);
    }
}
