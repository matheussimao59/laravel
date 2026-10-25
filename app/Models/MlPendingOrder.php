<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlPendingOrder extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['analysis_token'];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'evidence' => 'array', 'is_pending' => 'boolean',
            'order_tags' => 'array',
            'pack_size' => 'integer', 'buyer_id' => 'integer', 'seller_id' => 'integer',
            'group_confirmed' => 'boolean', 'messages_complete' => 'boolean',
            'ordered_at' => 'datetime', 'messages_checked_at' => 'datetime', 'synced_at' => 'datetime',
        ];
    }
}
