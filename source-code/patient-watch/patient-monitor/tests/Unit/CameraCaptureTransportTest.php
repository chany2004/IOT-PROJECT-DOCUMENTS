<?php

namespace Tests\Unit;

use App\Exceptions\CameraCaptureException;
use App\Jobs\CaptureEventImage;
use App\Models\Device;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Http, Log};
use ReflectionMethod;
use Tests\TestCase;

class CameraCaptureTransportTest extends TestCase
{
    private const CAPTURE_KEY = 'unit-test-only-not-a-real-camera-key';

    protected function setUp(): void
    {
        parent::setUp();
        // No event records, database writes, actual images, or network requests.
        Http::preventStrayRequests();
        Log::spy();
        config(['monitor.camera_capture_attempts' => 2,
            'monitor.camera_retry_delay_ms' => 0]);
    }

    private function camera(): Device
    {
        return new Device(['camera_ip' => '192.168.1.90', 'camera_port' => 80,
            'camera_key' => self::CAPTURE_KEY]);
    }

    private function capture(Device $camera): string
    {
        $job = new CaptureEventImage(0);
        return (new ReflectionMethod($job, 'captureJpeg'))->invoke($job, $camera);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        try {
            imagejpeg($image, null, 75);
            $jpeg = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        return $jpeg;
    }

    public function test_complete_authenticated_jpeg_is_downloaded_in_one_request(): void
    {
        $jpeg = $this->jpeg();
        $response = Http::response($jpeg, 200,
            ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg)]);
        // Guzzle normalizes the default HTTP port out of the request URL.
        Http::fake(['http://192.168.1.90/capture' => $response,
            'http://192.168.1.90:80/capture' => $response]);

        $this->assertSame($jpeg, $this->capture($this->camera()));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->hasHeader('X-Camera-Key', self::CAPTURE_KEY));
    }

    public function test_fresh_timestamped_frame_is_accepted(): void
    {
        $jpeg = $this->jpeg();
        Http::fake(['*' => Http::response($jpeg, 200, [
            'Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg),
            'X-Camera-Request-Us' => '5000000000', 'X-Camera-Frame-Us' => '5000001000',
            'X-Camera-Frame-Age-Ms' => '120',
        ])]);
        $this->assertSame($jpeg, $this->capture($this->camera()));
        Http::assertSentCount(1);
    }

    public function test_stale_or_malformed_freshness_is_never_saved_or_retried(): void
    {
        $jpeg = $this->jpeg();
        $headers = [];
        Http::fake(function () use ($jpeg, &$headers) {
            return Http::response($jpeg, 200, $headers);
        });
        foreach ([['99', '98', '120'], ['99', '100', '2000'], ['99', '2000099', '0'],
            ['99', '100', null], ['99', '-1', '0'], ['99', 'invalid', '0'],
            ['99', '999999999999999999999', '0']] as [$requestUs, $frameUs, $ageMs]) {
            $headers = ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg),
                'X-Camera-Request-Us' => $requestUs, 'X-Camera-Frame-Us' => $frameUs];
            if ($ageMs !== null) $headers['X-Camera-Frame-Age-Ms'] = $ageMs;
            try {
                $this->capture($this->camera());
                $this->fail('A valid JPEG must not bypass the freshness check.');
            } catch (CameraCaptureException $exception) {
                $this->assertSame('camera_frame_not_fresh', $exception->cameraError);
                $this->assertFalse($exception->retryable);
            }
        }
        Http::assertSentCount(7);
    }

    public function test_wrong_capture_key_is_not_retried(): void
    {
        $error = json_encode(['error' => 'camera_key_required']);
        Http::fake(['*' => Http::response($error, 401,
            ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($error)])]);

        try {
            $this->capture($this->camera());
            $this->fail('An unauthenticated capture must not be accepted.');
        } catch (CameraCaptureException $exception) {
            $this->assertSame('camera_key_required', $exception->cameraError);
        }
        Http::assertSentCount(1);
    }

    public function test_incomplete_jpeg_is_never_accepted_as_a_fast_snapshot(): void
    {
        config(['monitor.camera_capture_attempts' => 1]);
        $jpeg = "\xFF\xD8incomplete";
        Http::fake(['*' => Http::response($jpeg, 200,
            ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg)])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incomplete or corrupt camera JPEG response');
        $this->capture($this->camera());
    }

    public function test_offline_camera_connection_failure_does_not_add_a_second_wait(): void
    {
        Http::fake(fn () => throw new ConnectionException('Failed to connect to camera'));
        try {
            $this->capture($this->camera());
            $this->fail('An offline camera must not return a snapshot.');
        } catch (ConnectionException) {
            // A camera without a recent heartbeat must fail promptly.
        }
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $message === 'Camera snapshot attempt failed'
                && $context['attempt'] === 1 && $context['retrying'] === false
        );
    }
}
