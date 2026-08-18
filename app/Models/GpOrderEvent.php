<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpOrderEvent extends Model
{
    use HasFactory;

    protected $table = 'gp_order_events';

    protected $fillable = [
        'order_id',
        'status',
        'note',
        'created_by',
    ];

    public function order()
    {
        return $this->belongsTo(GpOrder::class, 'order_id');
    }
}
