<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpMaterial extends Model
{
    use HasFactory;

    protected $table = 'gp_materials';

    protected $fillable = [
        'user_id',
        'name',
        'unit',
        'unit_cost',
        'total_paid',
        'quantity_purchased',
        'stock_qty',
        'min_stock',
        'supplier_id',
        'image_url',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'quantity_purchased' => 'decimal:3',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supplier()
    {
        return $this->belongsTo(GpSupplier::class, 'supplier_id');
    }

    public function products()
    {
        return $this->belongsToMany(GpProduct::class, 'gp_product_materials', 'material_id', 'product_id')
            ->withPivot('qty_needed', 'cost_override')
            ->withTimestamps();
    }
}
