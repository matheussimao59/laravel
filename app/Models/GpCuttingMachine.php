<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpCuttingMachine extends Model
{
    use HasFactory;

    protected $table = 'gp_cutting_machines';

    protected $fillable = [
        'user_id',
        'name',
        'manufacturer',
        'model',
        'sheet_width',
        'sheet_height',
        'usable_width',
        'usable_height',
        'spacing',
        'margin',
        'default_sheet',
        'notes',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'sheet_width' => 'decimal:2',
            'sheet_height' => 'decimal:2',
            'usable_width' => 'decimal:2',
            'usable_height' => 'decimal:2',
            'spacing' => 'decimal:3',
            'margin' => 'decimal:3',
            'is_default' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->hasMany(GpProduct::class, 'cutting_machine_id');
    }
}