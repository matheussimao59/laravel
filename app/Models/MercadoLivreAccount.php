<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MercadoLivreAccount extends Model
{
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'token_type',
        'scope',
        'expires_at',
        'refresh_expires_at',
        'seller_id',
        'seller_nickname',
        'seller_first_name',
        'seller_last_name',
        'seller_payload',
        'last_synced_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'seller_payload' => 'array',
        ];
    }

    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->decryptValue($value),
            set: fn (?string $value) => $this->encryptValue($value),
        );
    }

    protected function refreshToken(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->decryptValue($value),
            set: fn (?string $value) => $this->encryptValue($value),
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    private function encryptValue(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return Crypt::encryptString($normalized);
    }

    private function decryptValue(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return Crypt::decryptString($normalized);
        } catch (\Throwable) {
            return null;
        }
    }
}
