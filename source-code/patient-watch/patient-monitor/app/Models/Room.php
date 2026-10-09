<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $guarded = ['id'];
    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn ($query) => $query->whereNull('rooms.archived_at'));
    }
    public function patient() { return $this->hasOne(Patient::class); }
}
