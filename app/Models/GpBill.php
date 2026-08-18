<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpBill extends Model
{
    use HasFactory;

    protected $table = 'gp_bills';

    protected $fillable = [
        'user_id',
        'description',
        'amount',
        'due_date',
        'paid',
        'paid_date',
        'category',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid' => 'boolean',
            'due_date' => 'date',
            'paid_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
