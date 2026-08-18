<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpQuoteItem extends Model
{
    use HasFactory;

    protected $table = 'gp_quote_items';

    protected $fillable = [
        'quote_id',
        'product_id',
        'product_name',
        'qty',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function quote()
    {
        return $this->belongsTo(GpQuote::class, 'quote_id');
    }

    public function product()
    {
        return $this->belongsTo(GpProduct::class, 'product_id');
    }
}
