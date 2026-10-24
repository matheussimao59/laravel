<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlBotSetting extends Model
{
    protected $table = 'ml_bot_settings';

    protected $fillable = [
        'user_id',
        'start_date',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
