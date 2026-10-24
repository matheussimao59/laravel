<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlBotOrder extends Model
{
    // Status possiveis do comando:
    // pending  -> aguardando confirmacao manual
    // sent     -> mensagem enviada ao comprador
    // skipped  -> marcado como "nao enviar"
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'ml_bot_orders';

    protected $fillable = [
        'user_id',
        'group_id',
        'order_id',
        'pack_id',
        'buyer_id',
        'title',
        'thumbnail',
        'sku',
        'qty',
        'status',
        'buyer_replied',
        'seller_messaged',
        'buyer_messages',
        'seller_messages',
        'sent_message',
        'sent_at',
        'order_date',
        'conversation_checked_at',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'buyer_replied' => 'boolean',
        'seller_messaged' => 'boolean',
        'buyer_messages' => 'integer',
        'seller_messages' => 'integer',
        'sent_at' => 'datetime',
        'order_date' => 'datetime',
        'conversation_checked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MlBotGroup::class, 'group_id');
    }

    /*
     * Aba efetiva para o carrossel do bot:
     * cliente que ja respondeu vira aba propria, mesmo que ja tenha
     * recebido mensagem; apos isso vem enviado, ignorado e pendente.
     */
    public function bucket(): string
    {
        if ($this->status === self::STATUS_SENT) {
            return $this->buyer_replied ? 'replied' : 'sent';
        }
        if ($this->status === self::STATUS_SKIPPED) {
            return 'skipped';
        }
        return $this->buyer_replied ? 'replied' : 'pending';
    }
}
