<?php

namespace App\Support;

final class MlPendingShipment
{
    /** null significa que a API ainda nao permitiu confirmar o estado do envio. */
    public static function classify(array $order, ?array $shipment): ?bool
    {
        if (!in_array($order['status'] ?? '', ['paid', 'partially_refunded'], true)) return false;
        $tags = is_array($order['tags'] ?? null) ? $order['tags'] : [];
        if (array_intersect($tags, ['delivered', 'shipped', 'not_delivered', 'cancelled']) !== []) return false;
        $status = strtolower((string) ($shipment['status'] ?? $order['shipping']['status'] ?? ''));
        if (in_array($status, ['shipped', 'delivered', 'not_delivered', 'cancelled', 'returned'], true)) return false;
        if (in_array($status, ['pending', 'handling', 'ready_to_ship', 'to_be_agreed', 'waiting_for_authorization', 'invoice_pending'], true)) return true;

        return null;
    }
}
