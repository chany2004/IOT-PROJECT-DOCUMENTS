<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatcherTelegramLink extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['token_hash', 'excluded_chat_hash'];
    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
