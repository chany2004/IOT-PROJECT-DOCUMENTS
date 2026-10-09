<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatcherSmsNotification extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['attempted_at' => 'datetime', 'submitted_at' => 'datetime'];

    public function event() { return $this->belongsTo(FallEvent::class, 'fall_event_id'); }
    public function confirmer() { return $this->belongsTo(User::class, 'confirmed_by'); }

    public function canRetry(): bool
    {
        return in_array($this->status, ['blocked', 'failed'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Waiting to send',
            'processing' => 'Sending SMS',
            'submitted' => 'Submitted to SMS provider',
            'blocked' => 'SMS not ready',
            'failed' => 'SMS rejected',
            'unknown' => 'Submission uncertain',
            'cancelled' => 'SMS cancelled',
            default => 'Status unavailable',
        };
    }
}
