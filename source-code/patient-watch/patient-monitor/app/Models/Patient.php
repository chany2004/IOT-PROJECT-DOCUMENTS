<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Patient extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean', 'birth_date' => 'date', 'emergency_sms_enabled' => 'boolean',
        'watcher_telegram_enabled' => 'boolean', 'watcher_telegram_linked_at' => 'datetime'];
    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn (Builder $query) => $query->whereNull('patients.archived_at'));
    }
    public function room() { return $this->belongsTo(Room::class); }
    public function caregiver() { return $this->belongsTo(User::class, 'caregiver_id'); }
    public function watcherTelegramLink() { return $this->hasOne(WatcherTelegramLink::class); }
    public function assignments() { return $this->hasMany(DeviceAssignment::class); }
    public function currentAssignments() { return $this->assignments()->whereNull('unassigned_at')->whereHas('device', fn ($q) => $q->where('is_simulated', false)); }
    public function events() { return $this->hasMany(FallEvent::class)->where('is_simulated', false); }
    public function readings() { return $this->hasMany(SensorReading::class)->where('is_simulated', false); }
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('caregiver_id', $user->id);
    }
}
