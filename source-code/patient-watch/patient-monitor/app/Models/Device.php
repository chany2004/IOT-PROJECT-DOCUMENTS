<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['key_hash', 'camera_key'];
    protected $casts = ['is_active' => 'boolean', 'is_simulated' => 'boolean', 'sensor_ok' => 'boolean', 'last_seen_at' => 'datetime', 'camera_key' => 'encrypted'];
    public function assignments() { return $this->hasMany(DeviceAssignment::class); }
    public function currentAssignment() { return $this->hasOne(DeviceAssignment::class)->whereNull('unassigned_at'); }
    public function isOnline(): bool
    {
        return !$this->is_simulated && $this->is_active && $this->last_seen_at && $this->last_seen_at->gt(now()->subSeconds(config('monitor.offline_after_seconds')));
    }
}
