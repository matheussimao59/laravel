<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpSale extends Model
{
    use HasFactory;

    protected $table = 'gp_sales';

    protected $fillable = [
        'user_id',
        'client_name',
        'client_phone',
        'payment_method',
        'payment_status',
        'delivery_date',
        'discount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(GpSaleItem::class, 'sale_id');
    }
}