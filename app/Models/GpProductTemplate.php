<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpProductTemplate extends Model
{
    use HasFactory;

    protected $table = 'gp_product_templates';

    protected $fillable = [
        'user_id',
        'name',
        'category',
        'description',
        'width_mm',
        'height_mm',
        'material',
        'acabamento',
        'default_qty',
        'base_price',
        'cost_material',
        'cost_labor',
        'image_url',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'width_mm' => 'decimal:2',
            'height_mm' => 'decimal:2',
            'base_price' => 'decimal:2',
            'cost_material' => 'decimal:2',
            'cost_labor' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
