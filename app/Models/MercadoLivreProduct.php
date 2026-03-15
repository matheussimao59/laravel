<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MercadoLivreProduct extends Model
{
    protected $fillable = [
        'user_id',
        'item_id',
        'title',
        'current_price',
        'cost_price',
        'min_margin',
        'shipping_cost',
        'taxes',
        'auto_reprice',
        'competitors',
    ];

    protected $casts = [
        'current_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'min_margin' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'taxes' => 'decimal:2',
        'auto_reprice' => 'boolean',
        'competitors' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function calculateMargin($price = null): float
    {
        $price = $price ?? $this->current_price;
        $costs = ($this->cost_price ?? 0) + $this->shipping_cost + $this->taxes;
        if ($costs == 0) return 0;
        return (($price - $costs) / $price) * 100;
    }

    public function isMarginValid($price = null): bool
    {
        return $this->calculateMargin($price) >= $this->min_margin;
    }
}
