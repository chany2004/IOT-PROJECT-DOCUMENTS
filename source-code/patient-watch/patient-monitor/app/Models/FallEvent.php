<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class FallEvent extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['is_simulated' => 'boolean', 'detected_at' => 'datetime', 'received_at' => 'datetime',
        'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime', 'verified_at' => 'datetime'];
    public function getRouteKeyName(): string { return 'public_id'; }
    public function patient() { return $this->belongsTo(Patient::class); }
    public function device() { return $this->belongsTo(Device::class); }
    public function room() { return $this->belongsTo(Room::class); }
    public function reading() { return $this->belongsTo(SensorReading::class, 'reading_id'); }
    public function camera() { return $this->hasOne(CameraVerification::class); }
    public function updates() { return $this->hasMany(EventUpdate::class)->orderBy('id'); }
    public function acknowledger() { return $this->belongsTo(User::class, 'acknowledged_by'); }
    public function watcherSms() { return $this->hasOne(WatcherSmsNotification::class); }
    public function watcherTelegram() { return $this->hasOne(WatcherTelegramNotification::class); }
    public function requiresImmediateAttention(): bool
    {
        if (in_array($this->status, ['resolved', 'false_alarm'], true)) {
            return false;
        }
        return $this->event_type === 'manual_emergency'
            || in_array($this->verification_status, ['likely_fall', 'inconclusive'], true);
    }
    public function assessmentLabel(): string
    {
        if ($this->event_type === 'manual_emergency') {
            return 'Manual emergency';
        }
        return match ($this->verification_status) {
            'verifying' => 'Camera verifying',
            'likely_fall' => 'Likely fall',
            'unlikely_fall' => 'Camera indicates no fall',
            'inconclusive' => 'Review required',
            default => 'Possible fall',
        };
    }
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('is_simulated', false)->whereHas('patient', fn ($q) => $q->visibleTo($user));
    }
}
