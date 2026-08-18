<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpCashFlow extends Model
{
    use HasFactory;

    protected $table = 'gp_cash_flow';

    protected $fillable = [
        'user_id',
        'type',
        'description',
        'amount',
        'category',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
