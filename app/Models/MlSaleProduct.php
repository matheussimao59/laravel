<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlSaleProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pictures' => 'array', 'listing_price' => 'decimal:2', 'last_sale_price' => 'decimal:2',
            'last_sale_at' => 'datetime', 'details_synced_at' => 'datetime', 'costs_configured_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(GpProduct::class, 'gp_product_id');
    }
}
