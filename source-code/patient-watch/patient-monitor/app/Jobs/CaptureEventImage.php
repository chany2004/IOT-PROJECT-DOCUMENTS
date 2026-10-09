<?php
namespace App\Jobs;

use App\Exceptions\CameraCaptureException;
use App\Models\CameraVerification;
use App\Models\Device;
use App\Support\CameraJpeg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;

class CaptureEventImage implements ShouldQueue
{
    use Queueable;
    public $tries = 1;
    public $timeout = 60;
    public function __construct(public int $verificationId) {}

    public function handle(): void
    {
        $record = CameraVerification::with(['device.currentAssignment', 'event'])->findOrFail($this->verificationId);
        if ($record->status !== 'pending' || $record->is_simulated) return;
        if (!$record->device?->is_active || $record->device?->currentAssignment?->patient_id !== $record->event->patient_id) {
            $this->markInconclusive($record, 'unavailable',
                'Camera assignment changed before capture. Human review is required.');
            return;
        }
        if ($record->created_at->lt(now()->subSeconds(config('monitor.camera_queue_window_seconds')))) {
            $this->markInconclusive($record, 'timeout',
                'Capture did not begin within the allowed queue window. Human review is required.');
            return;
        }
        try {
            $device = $record->device;
            $jpeg = $this->captureJpeg($device);
            $path = 'captures/'.$record->event->public_id.'/'.$record->id.'.jpg';
            if (!Storage::disk('local')->put($path, $jpeg)) {
                throw new \RuntimeException('Unable to store camera evidence');
            }
            // Persist evidence before pose analysis; a slow or unavailable
            // verifier must never discard the image or close the incident.
            $isManualEmergency = $record->event->event_type === 'manual_emergency';
            $record->update(['status' => 'captured', 'image_path' => $path,
                'captured_at' => now(), 'result' => $isManualEmergency
                    ? 'Emergency snapshot saved for caregiver review.'
                    : 'JPEG captured. Pose verification is running.']);
            $device->update(['last_seen_at' => now()]);
            // A manual emergency is already actionable. Its photo is evidence,
            // not a reason to wait for the optional pose-analysis service.
            if ($isManualEmergency) return;
            [$verdict, $confidence, $reason] = $this->verifyPose($device, $jpeg);
            $record->update([
                'status' => 'captured',
                'verdict' => $verdict,
                'confidence' => $confidence,
                'verified_at' => now(),
                'result' => $this->resultMessage($verdict, $confidence, $reason),
            ]);
            $this->applyEventAssessment($record->fresh(['event']), $verdict, $reason);
        } catch (\Throwable $exception) {
            $fresh = $record->fresh(['event']);
            if ($fresh?->status === 'captured') {
                $fresh->update([
                    'verdict' => 'uncertain',
                    'verified_at' => now(),
                    'result' => 'JPEG captured, but automated pose verification failed. Human review is required.',
                ]);
                $this->applyEventAssessment($fresh, 'uncertain',
                    'Automated pose verification failed after the image was saved.');
                return;
            }
            $detail = $exception->getMessage();
            $reason = match (true) {
                !function_exists('imagecreatefromstring') => 'Camera worker is missing PHP GD. Human review is required.',
                $exception instanceof CameraCaptureException && $exception->cameraError === 'capture_failed'
                    => 'Camera is reachable, but could not capture a frame. Check camera power and the OV2640 connection. Check the patient now.',
                $exception instanceof CameraCaptureException && $exception->cameraError === 'camera_busy'
                    => 'Camera is busy and could not provide this snapshot. Check the patient now.',
                $exception instanceof CameraCaptureException && $exception->cameraError === 'camera_key_required'
                    => 'Camera rejected the snapshot request. An administrator must check the camera access key. Check the patient now.',
                $exception instanceof CameraCaptureException
                    => 'Camera returned an error instead of a snapshot. Check the camera connection and firmware. Check the patient now.',
                $exception instanceof ConnectionException && preg_match('/failed to connect|connection refused|could not resolve/i', $detail)
                    => 'Camera could not be reached at its saved LAN address. Connect the camera and web server to the same network. Check the patient now.',
                $exception instanceof ConnectionException
                    => 'The camera snapshot request timed out before a complete image arrived. Check the camera connection and power. Check the patient now.',
                default => 'Camera did not return a complete JPEG. Check camera power and connection, and review the patient now.',
            };
            // A response from the camera with capture_failed/camera_busy is
            // different from a transport timeout: the camera was reached,
            // but it could not produce a frame. Persist that distinction so
            // every client, including an older open page, shows the right
            // status.
            $cameraStatus = $exception instanceof CameraCaptureException
                && in_array($exception->cameraError, [
                    'capture_failed', 'camera_busy', 'camera_key_required', 'http_error',
                ], true)
                ? 'unavailable' : 'timeout';
            $this->markInconclusive($record, $cameraStatus, $reason);
        }
    }

    private function verifyPose(Device $device, string $jpeg): array
    {
        $verifierUrl = config('monitor.camera_verifier_url');
        $verifierKey = config('monitor.camera_verifier_key');
        if (!$verifierUrl || !$verifierKey) {
            return ['uncertain', null, 'Automated pose verifier is not configured.'];
        }
        try {
            $response = Http::withHeaders(['X-API-Key' => $verifierKey, 'X-Device-Id' => $device->code])
                ->connectTimeout(2)->timeout(12)->withBody($jpeg, 'image/jpeg')->post($verifierUrl);
            if (!$response->successful()) {
                return ['uncertain', null, 'Automated pose verifier was unavailable.'];
            }
            $verification = $response->json();
            $verdict = in_array($verification['verdict'] ?? null, ['fall', 'no_fall', 'uncertain'], true)
                ? $verification['verdict'] : 'uncertain';
            $confidence = is_numeric($verification['confidence'] ?? null)
                ? max(0.0, min(1.0, (float) $verification['confidence'])) : null;
            $reason = is_string($verification['reason'] ?? null)
                ? str_replace('_', ' ', $verification['reason']) : 'No reason supplied.';
            return [$verdict, $confidence, $reason];
        } catch (\Throwable) {
            return ['uncertain', null, 'Automated pose verifier timed out or could not be reached.'];
        }
    }

    private function resultMessage(string $verdict, ?float $confidence, string $reason): string
    {
        $label = match ($verdict) {
            'fall' => 'Likely fall detected',
            'no_fall' => 'Camera indicates no fall',
            default => 'Camera result is inconclusive',
        };
        $score = $confidence === null ? '' : ' Confidence '.number_format($confidence * 100, 1).'%.';
        return $label.'.'.$score.' Reason: '.$reason.' Caregiver confirmation remains required.';
    }

    private function applyEventAssessment(CameraVerification $record, string $verdict, string $reason): void
    {
        if ($record->event?->event_type !== 'possible_fall') {
            return;
        }
        $record->event->update([
            'verification_status' => match ($verdict) {
                'fall' => 'likely_fall',
                'no_fall' => 'unlikely_fall',
                default => 'inconclusive',
            },
            'verification_reason' => $reason,
            'verified_at' => now(),
        ]);
    }

    private function markInconclusive(CameraVerification $record, string $cameraStatus, string $reason): void
    {
        $record->update([
            'status' => $cameraStatus,
            'image_path' => null,
            'verdict' => 'uncertain',
            'confidence' => null,
            'verified_at' => now(),
            'result' => $reason,
        ]);
        $record->loadMissing('event');
        $this->applyEventAssessment($record, 'uncertain', $reason);
    }

    private function captureJpeg(Device $device): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('PHP GD is required to validate camera JPEGs.');
        }
        $attempts = min(2, max(1, (int) config('monitor.camera_capture_attempts', 2)));
        $maxBytes = max(1, (int) config('monitor.camera_max_image_bytes', 500000));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = hrtime(true);
            $phase = 'transport';
            $responseStatus = null;
            $receivedBytes = null;
            $declaredLength = null;
            try {
                $response = Http::withHeaders(['X-Camera-Key' => $device->camera_key ?? ''])
                    ->connectTimeout(config('monitor.camera_connect_timeout_seconds', 3))
                    ->timeout(config('monitor.camera_timeout_seconds', 12))
                    // stream=true selects PHP's stream handler, where the
                    // connection timeout is ignored and body reads can extend
                    // past the request timeout. Let cURL finish the bounded
                    // download before inspecting it. Firmware declares its
                    // JPEG length; reject oversized/unbounded bodies up front.
                    ->withOptions([
                        'allow_redirects' => false, 'proxy' => '',
                        'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                            $length = $response->getHeaderLine('Content-Length');
                            if (!ctype_digit($length) || (int) $length > $maxBytes) {
                                throw new \RuntimeException('Camera response requires a bounded Content-Length');
                            }
                        },
                    ])
                    ->get('http://'.$device->camera_ip.':'.$device->camera_port.'/capture');
                $responseStatus = $response->status();
                $phase = 'camera_response';
                $body = $response->toPsrResponse()->getBody();
                try {
                    $jpeg = '';
                    while (!$body->eof() && strlen($jpeg) <= $maxBytes) {
                        $jpeg .= $body->read(min(8192, ($maxBytes + 1) - strlen($jpeg)));
                    }
                } finally {
                    $body->close();
                }
                $declaredLength = $response->header('Content-Length');
                $receivedBytes = strlen($jpeg);
                if (!$response->successful()) {
                    // Only retain known firmware codes, never a raw device
                    // response (which might contain credentials or image data).
                    $payload = json_decode($jpeg, true);
                    $firmwareError = is_array($payload) ? ($payload['error'] ?? null) : null;
                    $cameraError = $responseStatus === 401 ? 'camera_key_required'
                        : (in_array($firmwareError, ['capture_failed', 'camera_busy', 'camera_key_required'], true)
                            ? $firmwareError : 'http_error');
                    $retryable = $cameraError !== 'camera_key_required'
                        && ($responseStatus >= 500 || in_array($responseStatus, [408, 429], true));
                    throw new CameraCaptureException($cameraError, $retryable);
                }
                $phase = 'jpeg_validation';
                $hasCompleteLength = $declaredLength === null || $declaredLength === ''
                    || (ctype_digit($declaredLength) && (int) $declaredLength === strlen($jpeg));
                if (strlen($jpeg) > $maxBytes ||
                    !$hasCompleteLength || !CameraJpeg::isUsable($jpeg)) {
                    throw new \RuntimeException('Incomplete or corrupt camera JPEG response');
                }
                // New firmware supplies boot-relative capture timestamps.
                // Reject a queued/old frame even when its JPEG decodes correctly.
                // Legacy cameras without these headers remain compatible.
                $freshness = [$response->header('X-Camera-Request-Us'),
                    $response->header('X-Camera-Frame-Us'),
                    $response->header('X-Camera-Frame-Age-Ms')];
                if (count(array_filter($freshness, static fn ($value) => $value !== null && $value !== ''))) {
                    $boundedNumbers = array_reduce($freshness,
                        static fn ($valid, $value) => $valid && is_string($value)
                            && ctype_digit($value) && strlen($value) <= 18, true);
                    if (!$boundedNumbers || (int) $freshness[0] <= 0
                        || (int) $freshness[1] < (int) $freshness[0]
                        || (int) $freshness[1] - (int) $freshness[0] >= 2000000
                        || (int) $freshness[2] >= 2000) {
                        throw new CameraCaptureException('camera_frame_not_fresh', false);
                    }
                }
                return $jpeg;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                // Retry a brief drop once when a recent heartbeat confirms
                // this camera was online. A stale/offline camera exits promptly.
                $retrying = $attempt < $attempts
                    && (!($exception instanceof CameraCaptureException) || $exception->retryable)
                    && (!($exception instanceof ConnectionException) || $device->isOnline());
                Log::warning('Camera snapshot attempt failed', [
                    'verification_id' => $this->verificationId,
                    'device_id' => $device->id,
                    'attempt' => $attempt,
                    'phase' => $phase,
                    'exception_type' => get_class($exception),
                    'camera_error' => $exception instanceof CameraCaptureException ? $exception->cameraError : null,
                    'elapsed_ms' => (int) ((hrtime(true) - $started) / 1000000),
                    'http_status' => $responseStatus,
                    'received_bytes' => $receivedBytes,
                    'declared_bytes' => $declaredLength,
                    'retrying' => $retrying,
                ]);
                if (!$retrying) break;
                usleep(max(0, (int) config('monitor.camera_retry_delay_ms', 350)) * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('Camera capture failed');
    }

    public function failed(?\Throwable $exception): void
    {
        $record = CameraVerification::with('event')->whereKey($this->verificationId)
            ->where('status', 'pending')->first();
        if ($record) {
            $this->markInconclusive($record, 'timeout',
                'Capture worker failed. Treat this as a possible fall until a caregiver checks the patient.');
        }
    }
}
