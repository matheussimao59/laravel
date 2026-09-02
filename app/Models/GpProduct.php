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
        'sku',
        'category',
        'category_id',
        'description',
        'sell_price',
        'pricing_type',
        'stock_qty',
        'unit',
        'cost_materials',
        'cost_labor',
        'cost_fixed',
        'cost_other',
        'active',
        'image_url',
        'cut_shape',
        'cut_width',
        'cut_height',
        'cutting_machine_id',
        'art_image_url',
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

    public function cuttingMachine()
    {
        return $this->belongsTo(GpCuttingMachine::class, 'cutting_machine_id');
    }

    public function materials()
    {
        return $this->belongsToMany(GpMaterial::class, 'gp_product_materials', 'product_id', 'material_id')
            ->withPivot('qty_needed', 'cost_override')
            ->withTimestamps();
    }

    public function quoteItems()
    {
        return $this->hasMany(GpQuoteItem::class, 'product_id');
    }

    public function getCalculatedCostMaterialsAttribute(): float
    {
        $total = 0.0;
        foreach ($this->materials as $material) {
            $unitCost = $material->pivot->cost_override ?? $material->unit_cost;
            $total += $unitCost * $material->pivot->qty_needed;
        }
        return round($total, 2);
    }
}
