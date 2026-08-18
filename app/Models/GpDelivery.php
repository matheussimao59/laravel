<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpDelivery extends Model
{
    use HasFactory;

    protected $table = 'gp_deliveries';

    protected $fillable = [
        'user_id',
        'order_id',
        'client_name',
        'product_name',
        'method',
        'status',
        'scheduled_date',
        'delivered_at',
        'address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'delivered_at' => 'datetime',
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
