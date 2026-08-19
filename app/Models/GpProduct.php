<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpProduct extends Model
{
    use HasFactory;

    protected $table = 'gp_products';

    protected $fillable = [
        'user_id',
        'name',
        'category',
        'category_id',
        'description',
        'sell_price',
        'pricing_type',
        'stock_qty',
        'cost_materials',
        'cost_labor',
        'cost_fixed',
        'cost_other',
        'active',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'sell_price' => 'decimal:2',
            'cost_materials' => 'decimal:2',
            'cost_labor' => 'decimal:2',
            'cost_fixed' => 'decimal:2',
            'cost_other' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gpCategory()
    {
        return $this->belongsTo(GpCategory::class, 'category_id');
    }

    public function quoteItems()
    {
        return $this->hasMany(GpQuoteItem::class, 'product_id');
    }
}
