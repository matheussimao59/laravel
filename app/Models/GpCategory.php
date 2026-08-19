<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpCategory extends Model
{
    use HasFactory;

    protected $table = 'gp_categories';

    protected $fillable = [
        'user_id',
        'name',
        'image_url',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->hasMany(GpProduct::class, 'category_id');
    }
}
