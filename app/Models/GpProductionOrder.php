<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpProductionOrder extends Model
{
    use HasFactory;

    protected $table = 'gp_production_orders';

    protected $fillable = [
        'user_id',
        'order_id',
        'client_name',
        'product_name',
        'qty',
        'total',
        'stage',
        'priority',
        'deadline',
        'notes',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'started_at' => 'datetime',
            'deadline' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(GpOrder::class, 'order_id');
    }
}
