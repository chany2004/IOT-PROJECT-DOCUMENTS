<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class DeviceAssignment extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    public function device() { return $this->belongsTo(Device::class); }
    public function patient() { return $this->belongsTo(Patient::class); }
}
