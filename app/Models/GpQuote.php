<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpQuote extends Model
{
    use HasFactory;

    protected $table = 'gp_quotes';

    protected $fillable = [
        'user_id',
        'client_id',
        'client_name',
        'discount',
        'total',
        'status',
        'notes',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(GpClient::class, 'client_id');
    }

    public function items()
    {
        return $this->hasMany(GpQuoteItem::class, 'quote_id');
    }

    public function orders()
    {
        return $this->hasMany(GpOrder::class, 'quote_id');
    }
}
