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
        'marks',
        'hide_default_marks',
        'item_count',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'marks' => 'array',
            'hide_default_marks' => 'boolean',
            'sheet_width_mm' => 'decimal:2',
            'sheet_height_mm' => 'decimal:2',
            'item_count' => 'integer',
        ];
    }

    /**
     * As marcas circulares padrão nao sao persistidas: sao sempre derivadas de
     * sheet_format + orientation. As marcas de registro importadas de SVG
     * (pontos pretos) sao persistidas neste campo.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
