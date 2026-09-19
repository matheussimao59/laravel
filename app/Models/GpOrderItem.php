<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpOrderItem extends Model
{
    use HasFactory;

    protected $table = 'gp_order_items';

    protected $fillable = [
        'order_id',
        'product_name',
        'product_size',
        'description',
        'qty',
        'sticker_qty',
        'unit_price',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'sticker_qty' => 'integer',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(GpOrder::class, 'order_id');
    }
}