<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SensorReading extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['axes' => 'array', 'is_simulated' => 'boolean', 'recorded_at' => 'datetime'];
    public function patient() { return $this->belongsTo(Patient::class); }
    public function device() { return $this->belongsTo(Device::class); }
}
