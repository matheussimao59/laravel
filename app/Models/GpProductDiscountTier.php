<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpProductDiscountTier extends Model
{
    use HasFactory;

    protected $table = 'gp_product_discount_tiers';

    protected $fillable = [
        'product_id',
        'min_qty',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'min_qty' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(GpProduct::class);
    }
}