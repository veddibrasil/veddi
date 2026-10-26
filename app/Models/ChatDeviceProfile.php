<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatDeviceProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['data', 'token_hash', 'phone_hash'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
