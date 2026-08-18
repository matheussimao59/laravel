<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpSupplier extends Model
{
    use HasFactory;

    protected $table = 'gp_suppliers';

    protected $fillable = [
        'user_id',
        'name',
        'cnpj',
        'phone',
        'email',
        'address',
        'products',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function materials()
    {
        return $this->hasMany(GpMaterial::class, 'supplier_id');
    }
}
