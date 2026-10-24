<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlBotItem extends Model
{
    protected $table = 'ml_bot_items';

    protected $fillable = [
        'user_id',
        'group_id',
        'item_id',
        'title',
        'thumbnail',
        'seller_sku',
        'price',
        'item_status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MlBotGroup::class, 'group_id');
    }
}
