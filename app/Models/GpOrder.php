<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpOrder extends Model
{
    use HasFactory;

    protected $table = 'gp_orders';

    protected $fillable = [
        'user_id',
        'quote_id',
        'client_id',
        'client_name',
        'client_phone',
        'product_name',
        'description',
        'qty',
        'unit_price',
        'total',
        'status',
        'payment_status',
        'payment_method',
        'payment_note',
        'delivery_method',
        'delivery_date',
        'deadline',
        'responsible',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_date' => 'date',
            'deadline' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quote()
    {
        return $this->belongsTo(GpQuote::class, 'quote_id');
    }

    public function client()
    {
        return $this->belongsTo(GpClient::class, 'client_id');
    }

    public function productionOrders()
    {
        return $this->hasMany(GpProductionOrder::class, 'order_id');
    }

    public function files()
    {
        return $this->hasMany(GpOrderFile::class, 'order_id');
    }

    public function events()
    {
        return $this->hasMany(GpOrderEvent::class, 'order_id');
    }

    public function deliveries()
    {
        return $this->hasMany(GpDelivery::class, 'order_id');
    }
}
