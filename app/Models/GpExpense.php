<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpExpense extends Model
{
    use HasFactory;

    protected $table = 'gp_expenses';

    protected $fillable = [
        'user_id',
        'description',
        'amount',
        'type',
        'category',
        'date',
        'recurring',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recurring' => 'boolean',
            'date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
