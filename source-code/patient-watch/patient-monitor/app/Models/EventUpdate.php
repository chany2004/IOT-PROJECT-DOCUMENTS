<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EventUpdate extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
