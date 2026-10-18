<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpArtePlotter extends Model
{
    use HasFactory;

    protected $table = 'gp_arte_plotters';

    protected $fillable = [
        'user_id',
        'name',
        'machine',
        'sheet_format',
        'orientation',
        'sheet_width_mm',
        'sheet_height_mm',
        'items',
        'item_count',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'sheet_width_mm' => 'decimal:2',
            'sheet_height_mm' => 'decimal:2',
            'item_count' => 'integer',
        ];
    }

    /**
     * As marcas circulares padrão não são persistidas: são sempre derivadas de
     * sheet_format + orientation. As marcas de registro importadas de SVG
     * (pontos pretos) são salvas dentro do campo items (kind: marcas_registro).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
