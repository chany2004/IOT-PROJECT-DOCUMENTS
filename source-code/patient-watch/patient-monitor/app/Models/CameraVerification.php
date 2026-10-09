<?php
namespace App\Models;
use App\Support\CameraJpeg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CameraVerification extends Model
{
    public const PUSH_PENDING_RESULT = 'Waiting for the assigned camera to upload a snapshot.';

    protected $guarded = ['id'];
    protected $hidden = ['image_path'];
    protected $casts = ['is_simulated' => 'boolean', 'captured_at' => 'datetime', 'verified_at' => 'datetime'];
    public function event() { return $this->belongsTo(FallEvent::class, 'fall_event_id'); }
    public function device() { return $this->belongsTo(Device::class, 'camera_device_id'); }

    public function hasDisplayableImage(): bool
    {
        if ($this->is_simulated || $this->status !== 'captured' || !$this->image_path) {
            return false;
        }
        // Cache the result because the monitoring feed polls frequently.
        // Version the key so previously accepted damaged images are rechecked.
        $key = 'camera-image-valid-v2:'.$this->id.':'.sha1($this->image_path.':'.
            $this->captured_at?->getTimestamp().':'.$this->updated_at?->getTimestamp());
        return Cache::remember($key, now()->addMinutes(10), function (): bool {
            try {
                $disk = Storage::disk('local');
                if (!$disk->exists($this->image_path)) return false;
                return CameraJpeg::isUsable($disk->get($this->image_path));
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function isFrameAcquisitionFailure(): bool
    {
        if (!in_array($this->status, ['timeout', 'unavailable'], true)
            || !is_string($this->result)) {
            return false;
        }

        return preg_match('/^Camera (?:is reachable, but could not capture a frame|is busy and could not provide this snapshot|returned an error instead of a snapshot)/i', $this->result) === 1;
    }
}
