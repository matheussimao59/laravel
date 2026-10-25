<?php

namespace App\Services;

use App\Models\MlPendingOrder;
use App\Models\User;
use App\Support\MlPendingShipment;
use App\Support\MlPersonalizationExtractor;
use App\Support\ExternalServiceException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

final class MlPendingOrderService
{
    public function __construct(private readonly MercadoLivreService $ml, private readonly MlPersonalizationExtractor $extractor)
    {
    }

    public function storeSnapshot(User $user, array $snapshot): MlPendingOrder
    {
        $order = $snapshot['order']; $sellerId = (int) $snapshot['seller_id'];
        $items = [];
        foreach ($order['order_items'] ?? [] as $row) {
            $item = $row['item'] ?? [];
            $items[] = ['id' => (string) ($item['id'] ?? ''), 'title' => (string) ($item['title'] ?? 'Produto'),
                'sku' => (string) ($item['seller_sku'] ?? ''), 'quantity' => max(1, (int) ($row['quantity'] ?? 1))];
        }
        try { $date = !empty($order['date_created']) ? Carbon::parse($order['date_created'])->utc() : null; }
        catch (\Exception) { $date = null; }

        $identity = ['user_id' => $user->id, 'seller_id' => $sellerId, 'order_id' => (string) $order['id']];
        $existing = MlPendingOrder::where($identity)->first();
        $packId = !empty($order['pack_id']) ? (string) $order['pack_id'] : null;
        $changedPack = $existing && $existing->pack_id !== $packId;
        $record = MlPendingOrder::updateOrCreate($identity, [
            'pack_id' => $packId,
            'pack_size' => array_key_exists('pack_size', $snapshot) ? $snapshot['pack_size'] : ($changedPack ? null : $existing?->pack_size),
            'buyer_id' => (int) ($order['buyer']['id'] ?? 0) ?: null,
            'buyer_label' => mb_substr((string) ($order['buyer']['nickname'] ?? ('Comprador #' . ($order['buyer']['id'] ?? ''))), 0, 255),
            'ordered_at' => $date, 'order_status' => (string) ($order['status'] ?? ''),
            'order_tags' => is_array($order['tags'] ?? null) ? array_values($order['tags']) : null,
            'shipment_id' => !empty($order['shipping']['id']) ? (string) $order['shipping']['id'] : null,
            'shipment_status' => $snapshot['shipment']['status'] ?? $order['shipping']['status'] ?? null,
            'is_pending' => MlPendingShipment::classify($order, $snapshot['shipment'] ?? null),
            'items' => $items, 'synced_at' => now(),
        ]);
        if ($changedPack && $record->personalization_source !== 'manual') $record->update(['personalization_status' => 'review', 'messages_complete' => false]);

        return $record;
    }

    public function purchase(User $user, MlPendingOrder $row)
    {
        $query = MlPendingOrder::where('user_id', $user->id)->where('seller_id', $row->seller_id);

        return $row->pack_id ? $query->where('pack_id', $row->pack_id) : $query->whereKey($row->id);
    }

    public function analyze(User $user, MlPendingOrder $row): array
    {
        $token = (string) Str::uuid();
        $this->purchase($user, $row)->update(['analysis_token' => $token, 'messages_complete' => false]);
        $purchase = $this->purchase($user, $row)->get();
        // Revalidacao leve antes de ler o chat: so o envio (os dados do pedido
        // vieram da sincronizacao). Isso mantem a analise dentro dos limites da API.
        foreach ($purchase as $member) {
            try {
                $shipment = $member->shipment_id
                    ? $this->ml->pendingShipmentForUser($user, (string) $member->shipment_id)
                    : null;
            } catch (ExternalServiceException|ConnectionException $exception) {
                $member->update(['is_pending' => null]);
                throw $exception;
            }
            $member->update([
                'shipment_status' => $shipment['status'] ?? $member->shipment_status,
                'is_pending' => MlPendingShipment::classify(
                    ['status' => $member->order_status, 'tags' => $member->order_tags],
                    $shipment
                ),
                'synced_at' => now(),
            ]);
            // Tamanho do carrinho e confirmado uma unica vez, so quando o envio segue pendente.
            if (($member->pack_id !== null) && $member->pack_size === null && $member->is_pending === true) {
                $member->update(['pack_size' => $this->ml->pendingPackSizeForUser($user, (string) $member->pack_id, $member->order_id)]);
            }
        }
        $row->refresh();
        $purchase = $this->purchase($user, $row)->get();
        if ($row->is_pending !== true) return ['analyzed' => false, 'message' => 'O envio nao esta pendente ou precisa ser verificado. Nenhuma mensagem foi enviada.'];

        $conversation = $this->ml->personalizationConversation($user, $row->order_id, $row->pack_id, (int) $row->buyer_id);
        $result = $this->extractor->extract($conversation['messages']);
        $shared = $purchase->count() > 1 || count($row->items ?? []) > 1 || ($row->pack_id && $row->pack_size !== 1);
        if (!$conversation['complete']) {
            $result['status'] = 'review';
            $result['note'] = $conversation['note'];
        } elseif ($shared && $result['status'] === 'identified') {
            $result['status'] = 'review';
            $result['note'] = 'Carrinho ou pedido com varios itens. Confirme a quais itens pertencem o nome e a idade antes de imprimir.';
        }

        DB::transaction(function () use ($user, $row, $conversation, $result, $token) {
            foreach ($this->purchase($user, $row)->where('analysis_token', $token)->lockForUpdate()->get() as $member) {
                // Uma revisao manual nunca e substituida pela leitura automatica.
                $member->messages_checked_at = now();
                $member->messages_complete = $conversation['complete'];
                if ($member->personalization_source !== 'manual') {
                    $member->personalization_name = $result['name'];
                    $member->personalization_age = $result['age'];
                    $member->personalization_status = $result['status'];
                    $member->analysis_note = $result['note'];
                    $member->evidence = $result['evidence'];
                    $member->observation = $result['status'] === 'identified'
                        ? "Nome: {$result['name']} | Idade: {$result['age']}"
                        : $result['note'];
                }
                $member->save();
            }
        });

        return ['analyzed' => true, 'complete' => $conversation['complete'], 'message' => $result['note']];
    }

    public function ready(MlPendingOrder $row): bool
    {
        if ($row->is_pending !== true || !$row->personalization_name || !$row->personalization_age) return false;

        return ($row->personalization_source === 'manual' && $row->personalization_status === 'confirmed')
            || ($row->personalization_status === 'identified' && $row->messages_complete);
    }

    public function printLookup(User $user, int $sellerId, array $ids): array
    {
        $rows = MlPendingOrder::where('user_id', $user->id)->where('seller_id', $sellerId)
            ->where(function ($query) use ($ids) { $query->whereIn('order_id', $ids)->orWhereIn('pack_id', $ids); })->get();
        $result = [];
        foreach ($ids as $id) {
            $matches = $rows->where('order_id', $id);
            $packAlias = $matches->isEmpty();
            if ($packAlias) $matches = $rows->where('pack_id', $id);
            if ($matches->isEmpty()) continue;
            $first = $matches->first();
            $ready = $matches->every(fn ($row) => $this->ready($row));
            if ($packAlias) $ready = $ready && $matches->every(fn ($row) => $row->pack_size !== null && $row->pack_size === $matches->count() && $row->group_confirmed);
            if ($matches->count() > 1) {
                $ready = $ready && $matches->every(fn ($row) => $row->group_confirmed)
                    && $matches->map(fn ($row) => [$row->personalization_name, $row->personalization_age])->uniqueStrict()->count() === 1;
            }
            $result[(string) $id] = [
                'ready' => $ready, 'order_id' => $first->order_id, 'status' => $ready ? $first->personalization_status : 'review',
                'name' => $ready ? $first->personalization_name : null, 'age' => $ready ? $first->personalization_age : null,
                'observation' => $ready ? $first->observation : null,
                'group_confirmed' => $matches->every(fn ($row) => $row->group_confirmed),
                'updated_at' => $matches->max('updated_at')?->toIso8601String(),
            ];
        }

        return $result;
    }
}
