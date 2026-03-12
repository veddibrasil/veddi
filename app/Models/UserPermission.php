<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'company_id', 'permission_id', 'granted'];

    protected $casts = ['granted' => 'boolean'];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
