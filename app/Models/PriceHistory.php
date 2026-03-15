<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    protected $fillable = [
        'mercado_livre_product_id',
        'old_price',
        'new_price',
        'reason',
        'details',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'details' => 'array',
    ];

    public function mercadoLivreProduct(): BelongsTo
    {
        return $this->belongsTo(MercadoLivreProduct::class);
    }
}
