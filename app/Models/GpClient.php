<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpClient extends Model
{
    use HasFactory;

    protected $table = 'gp_clients';

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'address',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quotes()
    {
        return $this->hasMany(GpQuote::class, 'client_id');
    }

    public function orders()
    {
        return $this->hasMany(GpOrder::class, 'client_id');
    }
}
