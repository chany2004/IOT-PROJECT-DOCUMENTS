<?php

namespace Tests\Feature;

use App\Jobs\SendWatcherSms;
use App\Models\{Device, DeviceAssignment, FallEvent, Patient, User, WatcherSmsNotification};
use App\Services\WatcherSmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{Http, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class WatcherSmsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $caregiver;
    private Patient $patient;
    private string $deviceKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('patient_monitor_test', config('database.connections.pgsql.database'));
        config(['watcher.channel' => 'semaphore', 'sms.enabled' => true, 'sms.semaphore_api_key' => 'fake-key-not-a-real-credential', 'sms.semaphore_sender_name' => 'PatientWatch']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = $this->user('administrator');
        $this->caregiver = $this->user('caregiver');
        $this->patient = Patient::create([
            'code' => (string) Str::uuid(), 'full_name' => 'Watcher SMS patient', 'caregiver_id' => $this->caregiver->id,
            'is_active' => true, 'emergency_contact_name' => 'Patient guardian',
            'emergency_contact_phone' => '+639171234567', 'emergency_sms_enabled' => true,
        ]);
        $this->deviceKey = Str::random(64);
        $device = Device::create(['code' => Str::uuid(), 'name' => 'Physical sensor', 'type' => 'esp32',
            'key_hash' => hash('sha256', $this->deviceKey), 'is_active' => true, 'is_simulated' => false]);
        DeviceAssignment::create(['device_id' => $device->id, 'patient_id' => $this->patient->id,
            'device_type' => 'esp32', 'assigned_at' => now()->subMinute()]);
    }

    private function user(string $role): User
    {
        return User::create(['name' => 'SMS '.$role, 'email' => Str::uuid().'@example.test',
            'password' => Str::random(32), 'role' => $role, 'is_active' => true]);
    }

    private function event(string $type = 'possible_fall'): FallEvent
    {
        $payload = ['event_id' => 'sms-'.Str::uuid(), 'event_type' => $type];
        if ($type === 'possible_fall') $payload['sensor'] = ['acceleration_g' => 3.1, 'gyro_rad_s' => 0.3];
        $id = $this->postJson('/api/v1/events', $payload, ['X-Device-Key' => $this->deviceKey])->assertCreated()->json('event_id');
        return FallEvent::where('public_id', $id)->firstOrFail();
    }

    private function confirm(FallEvent $event, ?User $user = null): WatcherSmsNotification
    {
        $this->actingAs($user ?? $this->caregiver)->patch(route('events.update', $event), [
            'status' => 'acknowledged', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Patient checked in person; fall confirmed.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $event->watcherSms()->firstOrFail();
    }

    private function send(WatcherSmsNotification $record): void
    {
        app()->call([new SendWatcherSms($record->id), 'handle']);
    }

    private function acceptedResponse(string $recipient = '639171234567'): array
    {
        return [['message_id' => 12345, 'recipient' => $recipient, 'status' => 'Queued']];
    }

    public function test_patient_form_saves_a_separate_name_mobile_and_sms_preference(): void
    {
        $this->actingAs($this->admin)->get(route('patients.edit', $this->patient))->assertOk()
            ->assertSee('name="emergency_contact_name"', false)->assertSee('name="emergency_contact_phone"', false);
        $this->put(route('patients.update', $this->patient), [
            'code' => $this->patient->code, 'full_name' => $this->patient->full_name, 'is_active' => '1',
            'emergency_contact_name' => 'Watcher One', 'emergency_contact_phone' => '0917 123-4567',
            'emergency_contact_relationship' => 'Parent', 'emergency_sms_enabled' => '1',
        ])->assertRedirect(route('patients.show', $this->patient))->assertSessionHasNoErrors();
        $this->patient->refresh();
        $this->assertSame('Watcher One', $this->patient->emergency_contact_name);
        $this->assertSame('+639171234567', $this->patient->emergency_contact_phone);
        $this->assertTrue($this->patient->emergency_sms_enabled);
        $this->actingAs($this->caregiver)->get(route('patients.show', $this->patient))->assertOk()->assertSeeText('Watcher One');
        Queue::assertNotPushed(SendWatcherSms::class);
    }

    public function test_form_rejects_landlines_and_missing_contact_when_sms_is_enabled(): void
    {
        $data = ['code' => $this->patient->code, 'full_name' => $this->patient->full_name, 'is_active' => '1',
            'emergency_sms_enabled' => '1', 'emergency_contact_name' => 'Guardian', 'emergency_contact_phone' => '0281234567'];
        $this->actingAs($this->admin)->putJson(route('patients.update', $this->patient), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('emergency_contact_phone');
        $data['emergency_contact_phone'] = '';
        $data['emergency_contact_name'] = '';
        $this->putJson(route('patients.update', $this->patient), $data)
            ->assertUnprocessable()->assertJsonValidationErrors(['emergency_contact_phone', 'emergency_contact_name']);
    }

    public function test_legacy_contact_and_missing_new_fields_are_preserved(): void
    {
        $this->patient->update(['emergency_contact' => 'Legacy guardian and phone notes']);
        $this->actingAs($this->admin)->put(route('patients.update', $this->patient), [
            'code' => $this->patient->code, 'full_name' => 'Updated patient name', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Legacy guardian and phone notes', $this->patient->fresh()->emergency_contact);
        $this->assertTrue($this->patient->fresh()->emergency_sms_enabled);
        $this->assertSame('+639171234567', $this->patient->fresh()->emergency_contact_phone);
    }

    public function test_sensor_camera_and_acknowledgement_do_not_notify_without_human_confirmation(): void
    {
        $event = $this->event();
        $event->update(['verification_status' => 'likely_fall']);
        $this->actingAs($this->caregiver)->postJson(route('events.acknowledge', $event))->assertOk();
        $this->patch(route('events.update', $event), ['status' => 'acknowledged', 'note' => 'Checking the patient.'])->assertRedirect();
        $this->assertNull($event->watcherSms()->first());
        Queue::assertNotPushed(SendWatcherSms::class);
        Http::assertNothingSent();
    }

    public function test_admin_or_assigned_caregiver_confirmation_queues_once_per_event(): void
    {
        foreach ([$this->admin, $this->caregiver] as $user) {
            $event = $this->event();
            $record = $this->confirm($event, $user);
            $this->assertSame('pending', $record->status);
            $this->assertSame($user->id, $record->confirmed_by);
            $this->confirm($event, $user);
            $this->assertSame(1, $event->watcherSms()->count());
        }
        Queue::assertPushed(SendWatcherSms::class, 2);
        Queue::assertPushed(SendWatcherSms::class, fn ($job) => $job->queue === 'notifications' && $job->afterCommit === true);
        Http::assertNothingSent();
    }

    public function test_unassigned_caregiver_cannot_confirm_or_retry(): void
    {
        $event = $this->event();
        $record = $this->confirm($event);
        $record->update(['status' => 'failed']);
        $this->actingAs($this->user('caregiver'))->patchJson(route('events.update', $event), [
            'status' => 'acknowledged', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Unauthorized confirmation.',
        ])->assertNotFound();
        $this->postJson(route('events.watcher-sms.retry', $event))->assertNotFound();
        $this->get(route('events.show', $event))->assertNotFound();
    }

    public function test_false_alarm_patient_safe_and_manual_emergency_do_not_send(): void
    {
        foreach (['patient_safe', 'false_alarm'] as $outcome) {
            $event = $this->event();
            $this->actingAs($this->caregiver)->patch(route('events.update', $event), [
                'status' => $outcome === 'false_alarm' ? 'false_alarm' : 'resolved',
                'caregiver_outcome' => 'patient_safe', 'note' => 'Patient reviewed and safe.',
            ])->assertRedirect();
            $this->assertNull($event->watcherSms()->first());
        }
        $manual = $this->event('manual_emergency');
        $this->actingAs($this->admin)->patch(route('events.update', $manual), [
            'status' => 'acknowledged', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Manual event reviewed.',
        ])->assertRedirect();
        $this->assertNull($manual->watcherSms()->first());
        Queue::assertNotPushed(SendWatcherSms::class);
    }

    public function test_missing_sms_config_records_response_and_a_truthful_blocked_status(): void
    {
        config(['sms.semaphore_api_key' => '']);
        $event = $this->event();
        $record = $this->confirm($event);
        $this->assertSame('blocked', $record->status);
        $this->assertSame('confirmed_fall', $event->fresh()->caregiver_outcome);
        $this->actingAs($this->caregiver)->get(route('events.show', $event))->assertOk()
            ->assertSeeText('SMS not ready')->assertSeeText('SMS service is not configured');
        Queue::assertNotPushed(SendWatcherSms::class);
        $this->send($record);
        Http::assertNothingSent();
    }

    public function test_missing_contact_or_opt_out_blocks_sms_without_losing_confirmation(): void
    {
        foreach ([['emergency_contact_phone' => null], ['emergency_sms_enabled' => false]] as $change) {
            $this->patient->update(['emergency_contact_phone' => '+639171234567', 'emergency_sms_enabled' => true]);
            $this->patient->update($change);
            $record = $this->confirm($this->event());
            $this->assertSame('blocked', $record->status);
        }
        Queue::assertNotPushed(SendWatcherSms::class);
    }

    public function test_job_submits_expected_sms_and_duplicate_jobs_do_not_resend(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response($this->acceptedResponse(), 200)]);
        $record = $this->confirm($this->event());
        $this->send($record);
        $this->send($record);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.semaphore.co/api/v4/messages'
            && $request['number'] === '639171234567' && $request['apikey'] === 'fake-key-not-a-real-credential'
            && $request['sendername'] === 'PatientWatch'
            && str_contains($request['message'], 'Watcher SMS patient')
            && str_contains($request['message'], 'confirmed by care staff'));
        $record->refresh();
        $this->assertSame('submitted', $record->status);
        $this->assertSame('12345', $record->provider_message_id);
        $this->assertSame('queued', $record->provider_status);
        $this->assertNotNull($record->submitted_at);
        $this->actingAs($this->caregiver)->get(route('events.show', $record->event))->assertOk()
            ->assertSeeText('Submitted to SMS provider')->assertSeeText('Handset delivery is not confirmed');
        $this->postJson(route('events.watcher-sms.retry', $record->event))->assertUnprocessable();
    }

    public function test_opt_out_or_contact_change_before_worker_prevents_wrong_recipient_sms(): void
    {
        $record = $this->confirm($this->event());
        $this->patient->update(['emergency_contact_phone' => '+639181234567']);
        $this->send($record);
        $this->assertSame('blocked', $record->fresh()->status);
        $this->patient->update(['emergency_contact_phone' => '+639171234567']);
        $record = $this->confirm($this->event());
        $this->patient->update(['emergency_sms_enabled' => false]);
        $this->send($record);
        $this->assertSame('blocked', $record->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_retracted_confirmation_cancels_queued_sms(): void
    {
        $event = $this->event();
        $record = $this->confirm($event);
        $this->actingAs($this->caregiver)->patch(route('events.update', $event), [
            'status' => 'false_alarm', 'note' => 'Further review ruled out a fall.',
        ])->assertRedirect();
        $this->send($record);
        $this->assertSame('cancelled', $record->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_provider_rejection_is_not_reported_as_sent_and_can_be_retried(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response(['error' => 'Invalid sender'], 422)]);
        $event = $this->event();
        $record = $this->confirm($event);
        $this->send($record);
        $this->assertSame('failed', $record->fresh()->status);
        $this->actingAs($this->caregiver)->post(route('events.watcher-sms.retry', $event))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $record->fresh()->status);
        $this->postJson(route('events.watcher-sms.retry', $event))->assertUnprocessable();
        Queue::assertPushed(SendWatcherSms::class, 2);
    }

    public function test_blocked_sms_can_be_queued_after_setup_even_for_a_closed_confirmed_fall(): void
    {
        config(['sms.enabled' => false]);
        $event = $this->event();
        $record = $this->confirm($event);
        $this->actingAs($this->caregiver)->patch(route('events.update', $event), [
            'status' => 'resolved', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Assistance completed.',
        ])->assertRedirect();
        config(['sms.enabled' => true]);
        $this->post(route('events.watcher-sms.retry', $event))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame('resolved', $event->fresh()->status);
        Queue::assertPushed(SendWatcherSms::class, 1);
    }

    public function test_timeouts_and_ambiguous_provider_response_are_not_automatically_resent(): void
    {
        Http::fake(fn () => throw new ConnectionException('Simulated timeout'));
        $event = $this->event();
        $record = $this->confirm($event);
        $this->send($record);
        $this->assertSame('unknown', $record->fresh()->status);
        $this->actingAs($this->caregiver)->postJson(route('events.watcher-sms.retry', $event))->assertUnprocessable();
        Http::fake(['api.semaphore.co/*' => Http::response($this->acceptedResponse('639181234567'), 200)]);
        $record = $this->confirm($this->event());
        $this->send($record);
        $this->assertSame('unknown', $record->fresh()->status);
        $this->assertNull($record->fresh()->submitted_at);
    }

    public function test_archived_simulated_event_never_sends_sms(): void
    {
        $event = $this->event();
        $event->update(['is_simulated' => true, 'caregiver_outcome' => 'confirmed_fall']);
        app(WatcherSmsService::class)->queueForConfirmedFall($event, $this->admin);
        $this->assertNull($event->watcherSms()->first());
        Queue::assertNotPushed(SendWatcherSms::class);
    }

    public function test_server_error_or_malformed_success_is_never_mislabeled_as_sent(): void
    {
        foreach ([[['error' => 'Provider server error'], 500], [['invalid' => 'response'], 200]] as [$body, $status]) {
            Http::fake(['api.semaphore.co/*' => Http::response($body, $status)]);
            $record = $this->confirm($this->event());
            $this->send($record);
            $this->assertSame('unknown', $record->fresh()->status);
            $this->assertNull($record->fresh()->submitted_at);
            $this->assertFalse($record->fresh()->canRetry());
        }
    }
}
