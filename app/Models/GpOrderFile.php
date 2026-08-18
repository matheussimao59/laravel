<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpOrderFile extends Model
{
    use HasFactory;

    protected $table = 'gp_order_files';

    protected $fillable = [
        'order_id',
        'filename',
        'url',
        'mime_type',
        'size_bytes',
    ];

    public function order()
    {
        return $this->belongsTo(GpOrder::class, 'order_id');
    }
}
