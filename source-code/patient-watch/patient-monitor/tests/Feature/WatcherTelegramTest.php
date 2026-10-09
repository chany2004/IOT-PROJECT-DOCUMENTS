<?php

namespace Tests\Feature;

use App\Jobs\{SendWatcherSms, SendWatcherTelegram};
use App\Models\{Device, DeviceAssignment, FallEvent, Patient, User, WatcherTelegramLink, WatcherTelegramNotification};
use App\Services\{TelegramWatcherUpdates, WatcherSmsService, WatcherTelegramService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class WatcherTelegramTest extends TestCase
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
        config(['watcher.channel' => 'telegram', 'watcher.telegram_enabled' => true,
            'watcher.telegram_bot_token' => '123456:fake-token-for-tests', 'watcher.telegram_bot_username' => 'PatientWatchTestBot',
            'sms.enabled' => true, 'sms.semaphore_api_key' => 'fake-sms-key']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = $this->user('administrator');
        $this->caregiver = $this->user('caregiver');
        $this->patient = Patient::create([
            'code' => (string) Str::uuid(), 'full_name' => 'Telegram patient', 'caregiver_id' => $this->caregiver->id,
            'is_active' => true, 'emergency_contact_name' => 'Patient guardian', 'emergency_contact_phone' => '+639171234567',
            'emergency_sms_enabled' => true, 'watcher_telegram_enabled' => true, 'watcher_telegram_chat_id' => '987654321',
            'watcher_telegram_bot_id' => '123456', 'watcher_telegram_username' => 'guardian', 'watcher_telegram_linked_at' => now(),
        ]);
        $this->deviceKey = Str::random(64);
        $device = Device::create(['code' => (string) Str::uuid(), 'name' => 'Physical sensor', 'type' => 'esp32',
            'key_hash' => hash('sha256', $this->deviceKey), 'is_active' => true, 'is_simulated' => false]);
        DeviceAssignment::create(['device_id' => $device->id, 'patient_id' => $this->patient->id,
            'device_type' => 'esp32', 'assigned_at' => now()->subMinute()]);
    }

    private function user(string $role): User
    {
        return User::create(['name' => 'Telegram '.$role, 'email' => Str::uuid().'@example.test',
            'password' => Str::random(32), 'role' => $role, 'is_active' => true]);
    }

    private function event(string $type = 'possible_fall'): FallEvent
    {
        $payload = ['event_id' => 'tg-'.Str::uuid(), 'event_type' => $type];
        if ($type === 'possible_fall') $payload['sensor'] = ['acceleration_g' => 3.1, 'gyro_rad_s' => 0.3];
        $id = $this->postJson('/api/v1/events', $payload, ['X-Device-Key' => $this->deviceKey])->assertCreated()->json('event_id');
        return FallEvent::where('public_id', $id)->firstOrFail();
    }

    private function confirm(FallEvent $event, ?User $user = null): WatcherTelegramNotification
    {
        $this->actingAs($user ?? $this->caregiver)->patch(route('events.update', $event), [
            'status' => 'acknowledged', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Patient checked in person; fall confirmed.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        return $event->watcherTelegram()->firstOrFail();
    }

    private function send(WatcherTelegramNotification $record): void
    {
        app()->call([new SendWatcherTelegram($record->id), 'handle']);
    }

    private function accepted(string $chat = '987654321'): array
    {
        return ['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => (int) $chat, 'type' => 'private']]];
    }

    private function update(string $text, string $chat = '987654321', int $id = 700): array
    {
        return ['update_id' => $id, 'message' => ['text' => $text, 'chat' => ['id' => (int) $chat, 'type' => 'private'],
            'from' => ['id' => (int) $chat, 'is_bot' => false, 'username' => 'guardian']]];
    }

    private function createLink(): string
    {
        $this->patient->update(['watcher_telegram_chat_id' => null, 'watcher_telegram_linked_at' => null]);
        $this->actingAs($this->admin)->post(route('patients.watcher-telegram.connect', $this->patient))
            ->assertRedirect(route('patients.show', $this->patient))->assertSessionHasNoErrors()->assertSessionHas('telegram_link');
        parse_str(parse_url(session('telegram_link')['url'], PHP_URL_QUERY), $query);
        return substr($query['start'], 3);
    }

    private function replaceAccount(): string
    {
        $this->actingAs($this->caregiver)->post(route('patients.watcher-telegram.connect', $this->patient), ['replace_account' => 1])
            ->assertRedirect(route('patients.show', $this->patient))->assertSessionHasNoErrors()->assertSessionHas('telegram_link');
        parse_str(parse_url(session('telegram_link')['url'], PHP_URL_QUERY), $query);
        return substr($query['start'], 3);
    }

    public function test_account_replacement_rejects_old_account_and_only_sends_reviewed_alert_to_new_account(): void
    {
        $event = $this->event();
        $record = $this->confirm($event);
        $token = $this->replaceAccount();
        $link = $this->patient->watcherTelegramLink()->firstOrFail();
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
        $this->assertNull($this->patient->fresh()->watcher_telegram_linked_at);
        $this->assertSame('blocked', $record->fresh()->status);
        $this->assertSame(hash('sha256', '123456:987654321'), $link->excluded_chat_hash);
        $this->assertArrayNotHasKey('excluded_chat_hash', $link->toArray());
        $this->assertSame($this->caregiver->id, $link->created_by);
        $this->get(route('patients.show', $this->patient))->assertOk()->assertSeeText('Previous account disconnected.')
            ->assertSeeText('Use the NEW account.')->assertDontSee('987654321')->assertDontSee($link->excluded_chat_hash);
        $updates = app(TelegramWatcherUpdates::class);
        $this->assertStringContainsString('previous Telegram account', $updates->handleUpdate($this->update('/start pw_'.$token)));
        $this->assertNull($link->fresh()->consumed_at);
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
        $this->assertStringContainsString('Connected to Patient Watch', $updates->handleUpdate($this->update('/start pw_'.$token, '111111111')));
        $this->assertSame('111111111', $this->patient->fresh()->watcher_telegram_chat_id);
        Queue::assertPushed(SendWatcherTelegram::class, 1);
        $this->send($record);
        Http::assertNothingSent();
        $this->assertSame('blocked', $record->fresh()->status);
        $this->post(route('events.watcher-telegram.retry', $event))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('111111111', $record->fresh()->chat_id);
        Http::fake(['api.telegram.org/*' => Http::response($this->accepted('111111111'))]);
        $this->send($record);
        $this->send($record);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === '111111111');
        $this->assertSame('submitted', $record->fresh()->status);
    }

    public function test_regenerating_replacement_invitation_keeps_old_account_excluded(): void
    {
        $oldToken = $this->replaceAccount();
        $this->patient->watcherTelegramLink()->update(['expires_at' => now()->subSecond()]);
        $this->post(route('patients.watcher-telegram.connect', $this->patient))->assertRedirect()->assertSessionHasNoErrors();
        parse_str(parse_url(session('telegram_link')['url'], PHP_URL_QUERY), $query);
        $newToken = substr($query['start'], 3);
        $updates = app(TelegramWatcherUpdates::class);
        $this->assertStringContainsString('invalid or expired', $updates->handleUpdate($this->update('/start pw_'.$oldToken, '111111111')));
        $this->assertStringContainsString('previous Telegram account', $updates->handleUpdate($this->update('/start pw_'.$newToken)));
        $this->assertNull($this->patient->watcherTelegramLink()->firstOrFail()->consumed_at);
        $this->assertStringContainsString('Connected to Patient Watch', $updates->handleUpdate($this->update('/start pw_'.$newToken, '111111111')));
        Http::assertNothingSent();
    }

    public function test_replacement_flag_in_patient_form_disconnects_even_when_contact_details_are_unchanged(): void
    {
        $record = $this->confirm($this->event());
        $this->actingAs($this->admin)->put(route('patients.update', $this->patient), [
            'code' => $this->patient->code, 'full_name' => $this->patient->full_name, 'is_active' => 1,
            'emergency_contact_name' => $this->patient->emergency_contact_name,
            'emergency_contact_phone' => '09171234567', 'watcher_telegram_enabled' => 1, 'replace_watcher_account' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
        $this->assertSame('blocked', $record->fresh()->status);
        $link = $this->patient->watcherTelegramLink()->firstOrFail();
        $this->assertNotNull($link->consumed_at);
        $this->assertSame(hash('sha256', '123456:987654321'), $link->excluded_chat_hash);
        $this->assertSame($this->admin->id, $link->created_by);
        $token = $this->createLink();
        $this->assertStringContainsString('previous Telegram account', app(TelegramWatcherUpdates::class)->handleUpdate($this->update('/start pw_'.$token)));
        Http::assertNothingSent();
    }

    public function test_phone_change_blocks_pending_alerts_but_equivalent_phone_format_preserves_connection(): void
    {
        $record = $this->confirm($this->event());
        $data = ['code' => $this->patient->code, 'full_name' => $this->patient->full_name, 'is_active' => 1,
            'emergency_contact_name' => $this->patient->emergency_contact_name,
            'emergency_contact_phone' => '09171234567', 'watcher_telegram_enabled' => 1];
        $this->actingAs($this->admin)->put(route('patients.update', $this->patient), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('987654321', $this->patient->fresh()->watcher_telegram_chat_id);
        $this->assertSame('pending', $record->fresh()->status);
        $data['emergency_contact_phone'] = '09179999999';
        $this->put(route('patients.update', $this->patient), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
        $this->assertSame('blocked', $record->fresh()->status);
        $this->send($record);
        Http::assertNothingSent();
    }

    public function test_replacement_preserves_sent_and_uncertain_history_without_backfilling(): void
    {
        $sent = $this->confirm($this->event());
        Http::fake(['api.telegram.org/*' => Http::response($this->accepted())]);
        $this->send($sent);
        $uncertain = $this->confirm($this->event());
        $uncertain->update(['status' => 'unknown', 'status_reason' => 'Check the original watcher chat.']);
        $token = $this->replaceAccount();
        app(TelegramWatcherUpdates::class)->handleUpdate($this->update('/start pw_'.$token, '111111111'));
        $this->assertSame('submitted', $sent->fresh()->status);
        $this->assertSame('987654321', $sent->fresh()->chat_id);
        $this->assertNotNull($sent->fresh()->submitted_at);
        $this->assertSame('unknown', $uncertain->fresh()->status);
        $this->assertSame('987654321', $uncertain->fresh()->chat_id);
        $this->send($sent);
        $this->send($uncertain);
        Http::assertSentCount(1);
        Queue::assertPushed(SendWatcherTelegram::class, 2);
    }

    public function test_old_account_stop_cannot_disconnect_replacement_and_same_account_can_reconnect_after_plain_disconnect(): void
    {
        $token = $this->replaceAccount();
        $updates = app(TelegramWatcherUpdates::class);
        $updates->handleUpdate($this->update('/start pw_'.$token, '111111111'));
        $updates->handleUpdate($this->update('/stop'));
        $this->assertTrue($this->patient->fresh()->watcher_telegram_enabled);
        $this->assertSame('111111111', $this->patient->fresh()->watcher_telegram_chat_id);
        $this->delete(route('patients.watcher-telegram.disconnect', $this->patient))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->patient->watcherTelegramLink()->firstOrFail()->excluded_chat_hash);
        $token = $this->createLink();
        $this->assertStringContainsString('Connected to Patient Watch', $updates->handleUpdate($this->update('/start pw_'.$token, '111111111')));
        Http::assertNothingSent();
    }

    public function test_replacement_is_scoped_and_requires_an_explicit_boolean_choice(): void
    {
        $url = route('patients.watcher-telegram.connect', $this->patient);
        $this->actingAs($this->user('caregiver'))->postJson($url, ['replace_account' => 1])->assertNotFound();
        $this->actingAs($this->admin)->postJson($url, ['replace_account' => 'unexpected'])->assertUnprocessable()
            ->assertJsonValidationErrors('replace_account');
        $this->assertSame('987654321', $this->patient->fresh()->watcher_telegram_chat_id);
        $this->get(route('patients.show', $this->patient))->assertOk()->assertSeeText('Change Telegram account');
        Http::assertNothingSent();
    }

    public function test_recipient_lock_spans_http_request_and_is_released_after_send_or_exception(): void
    {
        $record = $this->confirm($this->event());
        $key = 'patient-watch:telegram-recipient:'.$this->patient->id;
        config(['database.connections.telegram_lock_probe' => config('database.connections.pgsql')]);
        $probe = DB::connection('telegram_lock_probe');
        try {
            Http::fake(function () use ($key, $probe) {
                $this->assertFalse($probe->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$key])->acquired);
                return Http::response($this->accepted());
            });
            $this->send($record);
            $this->assertTrue($probe->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$key])->acquired);
            $probe->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
            try {
                app(WatcherTelegramService::class)->withConnectionLock($this->patient->id, fn () => throw new \RuntimeException('Test lock release.'));
                $this->fail('The callback should throw.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Test lock release.', $exception->getMessage());
            }
            $this->assertTrue($probe->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$key])->acquired);
        } finally {
            $probe->select('SELECT pg_advisory_unlock_all()');
            DB::purge('telegram_lock_probe');
        }
        $this->assertSame('submitted', $record->fresh()->status);
    }

    public function test_patient_form_and_profile_use_telegram_and_do_not_expose_credentials(): void
    {
        $this->actingAs($this->admin)->get(route('patients.edit', $this->patient))->assertOk()
            ->assertSee('name="watcher_telegram_enabled"', false)->assertSeeText('Telegram')
            ->assertDontSee('name="emergency_sms_enabled"', false)->assertDontSee('fake-token-for-tests');
        $this->put(route('patients.update', $this->patient), ['code' => $this->patient->code,
            'full_name' => $this->patient->full_name, 'is_active' => 1, 'emergency_contact_name' => 'Patient guardian',
            'watcher_telegram_enabled' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->patient->fresh()->watcher_telegram_linked_at);
        $this->actingAs($this->caregiver)->get(route('patients.show', $this->patient))->assertOk()
            ->assertSeeText('Watcher connected')->assertSeeText('guardian')->assertDontSee('fake-token-for-tests');
        Queue::assertNotPushed(SendWatcherTelegram::class);
    }

    public function test_name_is_required_for_telegram_and_contact_change_disconnects_previous_watcher(): void
    {
        $data = ['code' => $this->patient->code, 'full_name' => $this->patient->full_name, 'is_active' => 1,
            'watcher_telegram_enabled' => 1, 'emergency_contact_name' => ''];
        $this->actingAs($this->admin)->putJson(route('patients.update', $this->patient), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('emergency_contact_name');
        $data['emergency_contact_name'] = 'New guardian';
        $this->put(route('patients.update', $this->patient), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
        $this->assertNull($this->patient->fresh()->watcher_telegram_linked_at);
    }

    public function test_only_scoped_users_can_create_or_disconnect_a_link(): void
    {
        $outsider = $this->user('caregiver');
        $this->actingAs($outsider)->postJson(route('patients.watcher-telegram.connect', $this->patient))->assertNotFound();
        $this->deleteJson(route('patients.watcher-telegram.disconnect', $this->patient))->assertNotFound();
        $this->actingAs($this->caregiver)->delete(route('patients.watcher-telegram.disconnect', $this->patient))->assertRedirect();
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
        $this->post(route('patients.watcher-telegram.connect', $this->patient))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_private_link_survives_profile_refresh_and_is_hidden_when_expired_replaced_or_consumed(): void
    {
        $token = $this->createLink();
        $url = session('telegram_link')['url'];
        for ($refresh = 0; $refresh < 2; $refresh++) {
            $this->get(route('patients.show', $this->patient))->assertOk()->assertSee($url)
                ->assertSee('/start pw_'.$token)->assertSeeText('Copy Start command')
                ->assertSee('data-status-url=', false)->assertSeeText('PatientWatchTestBot');
        }
        $link = $this->patient->watcherTelegramLink()->firstOrFail();
        $link->update(['expires_at' => now()->subSecond()]);
        $this->get(route('patients.show', $this->patient))->assertOk()->assertDontSee($url)
            ->assertSeeText('The previous private link expired.');
        $link->update(['expires_at' => now()->addMinutes(15), 'consumed_at' => now()]);
        $this->get(route('patients.show', $this->patient))->assertOk()->assertDontSee($url);
        $link->update(['consumed_at' => null, 'token_hash' => hash('sha256', Str::random(48))]);
        $this->get(route('patients.show', $this->patient))->assertOk()->assertDontSee($url);
    }

    public function test_connection_status_is_authorized_private_and_does_not_reveal_chat_or_invitation(): void
    {
        $url = route('patients.watcher-telegram.status', $this->patient);
        $this->actingAs($this->user('caregiver'))->getJson($url)->assertNotFound();
        $response = $this->actingAs($this->caregiver)->getJson($url)->assertOk()
            ->assertJsonPath('connected', true)->assertJsonPath('ready', true)
            ->assertDontSee('987654321')->assertDontSee('fake-token-for-tests');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $token = $this->createLink();
        $this->getJson($url)->assertOk()->assertJsonPath('connected', false)
            ->assertJsonPath('ready', false)->assertJsonPath('invitation_active', true)
            ->assertDontSee($token);
        app(TelegramWatcherUpdates::class)->handleUpdate($this->update('/start pw_'.$token));
        $this->getJson($url)->assertOk()->assertJsonPath('connected', true)
            ->assertJsonPath('ready', true)->assertJsonPath('invitation_active', false);
        $this->get(route('patients.show', $this->patient))->assertOk()->assertSeeText('Watcher connected')
            ->assertDontSee('/start pw_'.$token);
    }

    public function test_stale_private_link_is_hidden_from_another_staff_account_and_after_disconnect(): void
    {
        $this->createLink();
        $url = session('telegram_link')['url'];
        $this->actingAs($this->caregiver)->get(route('patients.show', $this->patient))
            ->assertOk()->assertDontSee($url);
        $this->actingAs($this->admin)->delete(route('patients.watcher-telegram.disconnect', $this->patient))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(session('watcher_telegram_links.'.$this->patient->id));
        $this->get(route('patients.show', $this->patient))->assertOk()->assertDontSee($url);
    }

    public function test_private_link_is_hashed_single_use_and_cannot_replace_a_connected_watcher(): void
    {
        $token = $this->createLink();
        $link = WatcherTelegramLink::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertNotSame($token, $link->token_hash);
        $reply = app(TelegramWatcherUpdates::class)->handleUpdate($this->update('/start pw_'.$token));
        $this->assertStringContainsString('Connected to Patient Watch', $reply);
        $this->assertSame('987654321', $this->patient->fresh()->watcher_telegram_chat_id);
        $this->assertNotNull($link->fresh()->consumed_at);
        $reply = app(TelegramWatcherUpdates::class)->handleUpdate($this->update('/start pw_'.$token, '111111111'));
        $this->assertStringContainsString('invalid or expired', $reply);
        $this->assertSame('987654321', $this->patient->fresh()->watcher_telegram_chat_id);
        $this->postJson(route('patients.watcher-telegram.connect', $this->patient))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_expired_replaced_disabled_and_wrong_bot_links_cannot_connect(): void
    {
        $old = $this->createLink();
        $token = $this->createLink();
        $updates = app(TelegramWatcherUpdates::class);
        $this->assertStringContainsString('invalid or expired', $updates->handleUpdate($this->update('/start pw_'.$old)));
        $link = WatcherTelegramLink::where('patient_id', $this->patient->id)->firstOrFail();
        $link->update(['expires_at' => now()->subSecond()]);
        $this->assertStringContainsString('invalid or expired', $updates->handleUpdate($this->update('/start pw_'.$token)));
        $link->update(['expires_at' => now()->addMinutes(15), 'bot_id' => '999999']);
        $this->assertStringContainsString('invalid or expired', $updates->handleUpdate($this->update('/start pw_'.$token)));
        $link->update(['bot_id' => '123456']);
        $this->patient->update(['watcher_telegram_enabled' => false]);
        $this->assertStringContainsString('invalid or expired', $updates->handleUpdate($this->update('/start pw_'.$token)));
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
    }

    public function test_groups_bots_and_spoofed_sender_ids_cannot_link(): void
    {
        $token = $this->createLink();
        foreach (['group', 'bot', 'mismatch'] as $case) {
            $update = $this->update('/start pw_'.$token);
            if ($case === 'group') $update['message']['chat']['type'] = 'group';
            if ($case === 'bot') $update['message']['from']['is_bot'] = true;
            if ($case === 'mismatch') $update['message']['from']['id'] = 111111111;
            $this->assertNull(app(TelegramWatcherUpdates::class)->handleUpdate($update));
        }
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
    }

    public function test_confirmation_queues_one_telegram_alert_never_sms_even_with_sms_credentials(): void
    {
        $event = $this->event();
        $this->assertNull($event->watcherTelegram()->first());
        $this->actingAs($this->caregiver)->postJson(route('events.acknowledge', $event))->assertOk();
        Queue::assertNotPushed(SendWatcherTelegram::class);
        $record = $this->confirm($event);
        $this->confirm($event, $this->admin);
        $this->assertSame('pending', $record->status);
        $this->assertSame(1, $event->watcherTelegram()->count());
        Queue::assertPushed(SendWatcherTelegram::class, 1);
        Queue::assertNotPushed(SendWatcherSms::class);
        $this->assertNull($event->watcherSms()->first());
        $this->get(route('events.show', $event))->assertOk()->assertSeeText('Telegram notification')
            ->assertSeeText('Waiting to send')->assertDontSeeText('Watcher SMS notification');
    }

    public function test_unassigned_caregiver_cannot_confirm_or_retry_another_patients_notification(): void
    {
        $event = $this->event();
        $this->confirm($event);
        $this->actingAs($this->user('caregiver'))->patchJson(route('events.update', $event), [
            'status' => 'acknowledged', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Not my patient.',
        ])->assertNotFound();
        $this->postJson(route('events.watcher-telegram.retry', $event))->assertNotFound();
    }

    public function test_manual_emergency_confirmation_notifies_on_save_and_does_not_send_duplicates(): void
    {
        $event = $this->event('manual_emergency');
        $this->actingAs($this->admin)->get(route('events.show', $event))->assertOk()
            ->assertSeeText('Telegram notification')->assertSeeText('Acknowledging an alert alone does not notify the watcher.');
        $record = $this->confirm($event, $this->admin);
        $this->assertStringContainsString('queued automatically', session('success'));
        $this->assertNull(session('warning'));
        $this->confirm($event);
        Queue::assertPushed(SendWatcherTelegram::class, 1);
        Queue::assertNotPushed(SendWatcherSms::class);
        $this->assertSame(1, $event->watcherTelegram()->count());
        Http::fake(['api.telegram.org/*' => Http::response($this->accepted())]);
        $this->send($record);
        $this->send($record);
        $this->confirm($event);
        $this->assertStringContainsString('already sent to Telegram', session('success'));
        Queue::assertPushed(SendWatcherTelegram::class, 1);
        Http::assertSentCount(1);
        $this->assertSame('submitted', $record->fresh()->status);
        $this->get(route('events.show', $event))->assertOk()->assertSeeText('Sent to Telegram');
    }

    public function test_saving_confirmation_requires_notes_before_notifying(): void
    {
        $event = $this->event('manual_emergency');
        $this->actingAs($this->admin)->patchJson(route('events.update', $event), [
            'status' => 'acknowledged', 'caregiver_outcome' => 'confirmed_fall', 'note' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertNull($event->fresh()->caregiver_outcome);
        $this->assertNull($event->watcherTelegram()->first());
        Queue::assertNotPushed(SendWatcherTelegram::class);
    }

    public function test_blocked_manual_notification_is_queued_on_save_after_watcher_connects(): void
    {
        $this->patient->update(['watcher_telegram_linked_at' => null]);
        $event = $this->event('manual_emergency');
        $record = $this->confirm($event);
        $this->assertSame('blocked', $record->status);
        $this->assertStringContainsString('press Start', session('warning'));
        $this->get(route('events.show', $event))->assertOk()
            ->assertSeeText('Watcher notification:')->assertSeeText('Telegram not ready');
        Queue::assertNotPushed(SendWatcherTelegram::class);

        $token = $this->createLink();
        app(TelegramWatcherUpdates::class)->handleUpdate($this->update('/start pw_'.$token));
        // Linking does not silently notify old incidents. A staff save triggers it.
        Queue::assertNotPushed(SendWatcherTelegram::class);
        $this->get(route('events.show', $event))->assertOk()->assertSeeText('Ready to send')
            ->assertSeeText('The watcher is now connected.')
            ->assertDontSeeText('Connect the watcher in the patient profile, then ask them to open the private Telegram link and press Start.');
        $saved = $this->confirm($event, $this->admin);
        $this->assertSame($record->id, $saved->id);
        $this->assertSame('pending', $saved->status);
        $this->assertNull($saved->status_reason);
        $this->assertStringContainsString('queued automatically', session('success'));
        $this->assertNull(session('warning'));
        $this->confirm($event);
        Queue::assertPushed(SendWatcherTelegram::class, 1);
        $this->assertSame(1, $event->watcherTelegram()->count());
        Http::assertNothingSent();
    }

    public function test_manual_safe_false_alarm_and_simulated_incidents_do_not_notify(): void
    {
        foreach (['possible_fall', 'manual_emergency'] as $type) {
            foreach (['patient_safe', 'false_alarm'] as $outcome) {
                $event = $this->event($type);
                $this->actingAs($this->caregiver)->patch(route('events.update', $event), [
                    'status' => $outcome === 'false_alarm' ? 'false_alarm' : 'resolved',
                    'caregiver_outcome' => 'patient_safe', 'note' => 'Patient reviewed and safe.',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $this->assertNull($event->watcherTelegram()->first());
            }
        }
        $manual = $this->event('manual_emergency');
        $this->actingAs($this->admin)->patch(route('events.update', $manual), [
            'status' => 'acknowledged', 'note' => 'Manual emergency still being checked.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($manual->watcherTelegram()->first());
        $event = $this->event();
        $event->update(['is_simulated' => true, 'caregiver_outcome' => 'confirmed_fall']);
        app(WatcherTelegramService::class)->queueForConfirmedFall($event, $this->admin);
        $this->assertNull($event->watcherTelegram()->first());
        Queue::assertNotPushed(SendWatcherTelegram::class);
    }

    public function test_incomplete_config_missing_link_opt_out_and_wrong_bot_are_truthfully_blocked(): void
    {
        foreach (['token', 'link', 'optout', 'bot'] as $case) {
            config(['watcher.telegram_bot_token' => '123456:fake-token-for-tests']);
            $this->patient->update(['watcher_telegram_linked_at' => now(), 'watcher_telegram_enabled' => true, 'watcher_telegram_bot_id' => '123456']);
            if ($case === 'token') config(['watcher.telegram_bot_token' => '']);
            if ($case === 'link') $this->patient->update(['watcher_telegram_linked_at' => null]);
            if ($case === 'optout') $this->patient->update(['watcher_telegram_enabled' => false]);
            if ($case === 'bot') $this->patient->update(['watcher_telegram_bot_id' => '999999']);
            $record = $this->confirm($this->event());
            $this->assertSame('blocked', $record->status);
            $this->send($record);
        }
        Queue::assertNotPushed(SendWatcherTelegram::class);
        Http::assertNothingSent();
    }

    public function test_job_submits_to_correct_private_chat_without_paid_broadcasts_and_no_duplicate(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response($this->accepted())]);
        $record = $this->confirm($this->event());
        $this->send($record);
        $this->send($record);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '987654321' && $request['allow_paid_broadcast'] === false
            && $request['protect_content'] === true && $request['disable_notification'] === false
            && str_contains($request['text'], 'Telegram patient') && !isset($request['parse_mode']));
        $this->assertSame('submitted', $record->fresh()->status);
        $this->assertSame('42', $record->fresh()->telegram_message_id);
        $this->assertNotNull($record->fresh()->submitted_at);
        $this->assertStringContainsString('does not confirm', $record->fresh()->status_reason);
    }

    public function test_opt_out_or_changed_recipient_before_sending_never_contacts_old_watcher(): void
    {
        foreach ([['watcher_telegram_enabled' => false], ['watcher_telegram_chat_id' => '111111111']] as $change) {
            $this->patient->update(['watcher_telegram_enabled' => true, 'watcher_telegram_chat_id' => '987654321']);
            $record = $this->confirm($this->event());
            $this->patient->update($change);
            $this->send($record);
            $this->assertSame('blocked', $record->fresh()->status);
        }
        Http::assertNothingSent();
    }

    public function test_retracted_confirmation_cancels_queued_message(): void
    {
        $event = $this->event();
        $record = $this->confirm($event);
        $event->update(['caregiver_outcome' => 'patient_safe']);
        $this->send($record);
        $this->assertSame('cancelled', $record->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_blocked_confirmed_fall_can_be_retried_after_linking_even_when_resolved(): void
    {
        $this->patient->update(['watcher_telegram_linked_at' => null]);
        $event = $this->event();
        $record = $this->confirm($event);
        $this->actingAs($this->caregiver)->patch(route('events.update', $event), [
            'status' => 'resolved', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Assistance completed.',
        ])->assertRedirect();
        $this->patient->update(['watcher_telegram_linked_at' => now()]);
        $this->post(route('events.watcher-telegram.retry', $event))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $record->fresh()->status);
        Queue::assertPushed(SendWatcherTelegram::class, 1);
    }

    public function test_blocked_resolved_manual_emergency_can_be_retried_after_linking(): void
    {
        $this->patient->update(['watcher_telegram_linked_at' => null]);
        $event = $this->event('manual_emergency');
        $record = $this->confirm($event);
        $this->actingAs($this->caregiver)->patch(route('events.update', $event), [
            'status' => 'resolved', 'caregiver_outcome' => 'confirmed_fall', 'note' => 'Assistance completed.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->patient->update(['watcher_telegram_linked_at' => now()]);
        $this->post(route('events.watcher-telegram.retry', $event))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $record->fresh()->status);
        Queue::assertPushed(SendWatcherTelegram::class, 1);
        Http::fake(['api.telegram.org/*' => Http::response($this->accepted())]);
        $this->send($record);
        $this->assertSame('submitted', $record->fresh()->status);
    }

    public function test_explicit_telegram_rejection_is_retryable_but_not_automatically_repeated(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Bot blocked'], 403)]);
        $event = $this->event();
        $record = $this->confirm($event);
        $this->send($record);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertTrue($record->fresh()->canRetry());
        $this->send($record);
        $this->confirm($event);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertStringContainsString('Telegram rejected', session('warning'));
        Queue::assertPushed(SendWatcherTelegram::class, 1);
        Http::assertSentCount(1);
        $this->actingAs($this->caregiver)->post(route('events.watcher-telegram.retry', $event))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $record->fresh()->status);
    }

    public function test_timeout_server_error_malformed_or_wrong_chat_response_is_uncertain_and_never_resent(): void
    {
        foreach (['timeout', 'server', 'malformed', 'wrongchat'] as $case) {
            Http::fake($case === 'timeout' ? fn () => throw new ConnectionException('Token-bearing URL must not be saved') : [
                'api.telegram.org/*' => Http::response(match ($case) {
                    'server' => ['ok' => false, 'error_code' => 500],
                    'wrongchat' => $this->accepted('111111111'),
                    default => ['ok' => true, 'result' => []],
                }, $case === 'server' ? 500 : 200),
            ]);
            $event = $this->event();
            $record = $this->confirm($event);
            $this->send($record);
            $this->assertSame('unknown', $record->fresh()->status);
            $this->assertFalse($record->fresh()->canRetry());
            $this->assertNull($record->fresh()->submitted_at);
            $this->assertStringNotContainsString('Token-bearing', $record->fresh()->status_reason);
            $queuedBefore = Queue::pushed(SendWatcherTelegram::class)->count();
            $this->confirm($event);
            $this->assertSame('unknown', $record->fresh()->status);
            $this->assertSame($queuedBefore, Queue::pushed(SendWatcherTelegram::class)->count());
            $this->actingAs($this->caregiver)->postJson(route('events.watcher-telegram.retry', $event))->assertUnprocessable();
        }
    }

    public function test_watcher_stop_disconnects_and_prevents_pending_alert(): void
    {
        $record = $this->confirm($this->event());
        $reply = app(TelegramWatcherUpdates::class)->handleUpdate($this->update('/stop'));
        $this->assertStringContainsString('alerts stopped', $reply);
        $this->assertFalse($this->patient->fresh()->watcher_telegram_enabled);
        $this->assertNull($this->patient->fresh()->watcher_telegram_chat_id);
        $this->send($record);
        $this->assertSame('blocked', $record->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_poller_saves_offset_and_ignores_old_updates_after_restart(): void
    {
        $token = $this->createLink();
        Http::fake([
            'api.telegram.org/*/getUpdates' => Http::sequence()
                ->push(['ok' => true, 'result' => [$this->update('/start pw_'.$token)]])
                ->push(['ok' => true, 'result' => [$this->update('/start pw_'.$token), $this->update('/help', '987654321', 701)]]),
            'api.telegram.org/*/sendMessage' => Http::response($this->accepted()),
        ]);
        $this->assertSame(1, app(TelegramWatcherUpdates::class)->pollOnce(0));
        $this->assertSame(701, (int) DB::table('telegram_bot_cursors')->where('bot_id', '123456')->value('update_offset'));
        $this->assertSame(1, app(TelegramWatcherUpdates::class)->pollOnce(0));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getUpdates') && $request['offset'] === 701);
        Http::assertSentCount(4);
        $this->assertSame('987654321', $this->patient->fresh()->watcher_telegram_chat_id);
    }

    public function test_telegram_check_does_not_send_alerts_or_print_bot_token(): void
    {
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true,
            'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'PatientWatchTestBot']])]);
        $this->artisan('telegram:check')->expectsOutput('Telegram bot verified. No incident notification was sent.')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getMe'));
        config(['watcher.telegram_bot_token' => '']);
        $this->artisan('telegram:check')->assertFailed();
    }

    public function test_poller_reports_safe_http_errors_without_exposing_provider_details(): void
    {
        Http::fake(['api.telegram.org/*/getUpdates' => Http::response([
            'ok' => false, 'error_code' => 409, 'description' => 'fake-token-for-tests must not be printed',
        ], 409)]);
        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutput('Telegram watcher registration is running. Do not run another poller or webhook for this bot.')
            ->expectsOutput('Telegram rejected watcher registration (HTTP 409). Check bot credentials and ensure no other poller or webhook uses this bot.')
            ->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_poller_reports_connection_errors_without_exposing_token_bearing_urls(): void
    {
        Http::fake(fn () => throw new ConnectionException('https://api.telegram.org/bot123456:fake-token-for-tests/getUpdates'));
        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutput('Telegram watcher registration is running. Do not run another poller or webhook for this bot.')
            ->expectsOutput('Telegram connection failed. Check server internet access.')
            ->expectsOutput('Safe diagnostic: ConnectionException.')
            ->assertFailed();
    }

    public function test_registration_uses_short_polls_with_transport_timeout_margin(): void
    {
        $httpOptions = [];
        Http::fake(function ($request, $options) use (&$httpOptions) {
            $httpOptions = $options;
            return Http::response(['ok' => true, 'result' => []]);
        });
        $this->assertSame(0, app(TelegramWatcherUpdates::class)->pollOnce());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getUpdates') && $request['timeout'] === 5);
        $this->assertSame(20, $httpOptions['timeout']);
        $this->assertSame(5, $httpOptions['connect_timeout']);
    }

    public function test_local_non_blocking_recovery_setting_is_supported_without_sending_alerts(): void
    {
        config(['watcher.telegram_poll_timeout' => 0]);
        Http::fake(['api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => []])]);
        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutput('Telegram watcher registration is running. Do not run another poller or webhook for this bot.')
            ->expectsOutput('Registration polling uses non-blocking recovery mode.')
            ->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getUpdates') && $request['timeout'] === 0);
        Queue::assertNotPushed(SendWatcherTelegram::class);
    }

    public function test_poller_recovers_a_lower_update_id_after_a_long_idle_period(): void
    {
        DB::table('telegram_bot_cursors')->insert(['bot_id' => '123456', 'update_offset' => 999999,
            'last_update_at' => now()->subDays(8)]);
        Http::fake([
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update('/help', '987654321', 100)]]),
            'api.telegram.org/*/sendMessage' => Http::response($this->accepted()),
        ]);
        $this->assertSame(1, app(TelegramWatcherUpdates::class)->pollOnce(0));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getUpdates') && $request['offset'] === 0);
        $this->assertSame(101, (int) DB::table('telegram_bot_cursors')->where('bot_id', '123456')->value('update_offset'));
    }

    public function test_old_pending_sms_job_is_cancelled_when_telegram_is_selected(): void
    {
        $event = $this->event();
        $event->update(['caregiver_outcome' => 'confirmed_fall']);
        $record = app(WatcherSmsService::class)->prepare($event, $this->admin);
        app()->call([new SendWatcherSms($record->id), 'handle']);
        $this->assertSame('cancelled', $record->fresh()->status);
        $this->actingAs($this->admin)->postJson(route('events.watcher-sms.retry', $event))->assertUnprocessable();
        Http::assertNothingSent();
    }
}
