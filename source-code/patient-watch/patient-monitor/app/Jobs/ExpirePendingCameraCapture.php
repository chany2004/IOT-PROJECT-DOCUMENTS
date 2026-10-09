<?php

namespace App\Jobs;

use App\Models\CameraVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ExpirePendingCameraCapture implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public int $verificationId) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $record = CameraVerification::with('event')->lockForUpdate()->find($this->verificationId);
            $window = max(1, (int) config('monitor.camera_push_window_seconds', 45));
            if (!$record || $record->status !== 'pending' || $record->is_simulated
                || $record->result !== CameraVerification::PUSH_PENDING_RESULT
                || $record->created_at->gt(now()->subSeconds($window))) {
                return;
            }

            $reason = 'Camera did not upload a snapshot in time. Check the patient now.';
            $record->update([
                'status' => 'timeout',
                'verdict' => 'uncertain',
                'confidence' => null,
                'verified_at' => now(),
                'result' => $reason,
            ]);
            if ($record->event?->event_type === 'possible_fall') {
                $record->event->update([
                    'verification_status' => 'inconclusive',
                    'verification_reason' => $reason,
                    'verified_at' => now(),
                ]);
            }
        });
    }
}
