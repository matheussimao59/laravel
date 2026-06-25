<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfTranslationJob extends Model
{
    protected $fillable = [
        'user_id',
        'original_name',
        'original_path',
        'translated_path',
        'status',
        'source_language',
        'target_language',
        'page_count',
        'spanish_blocks',
        'error_message',
        'meta',
        'processed_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
