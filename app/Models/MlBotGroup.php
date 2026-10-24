<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlBotGroup extends Model
{
    protected $table = 'ml_bot_groups';

    protected $fillable = [
        'user_id',
        'name',
        'template',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MlBotItem::class, 'group_id')
            ->orderBy('title');
    }
}
