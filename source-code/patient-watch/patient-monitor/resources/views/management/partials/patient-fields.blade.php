<section class="form-section">
    <div class="form-section-heading">
        <div>
            <div class="eyebrow">STEP 1</div>
            <h2>Patient details</h2>
            <p>Use the same patient code when matching hardware alerts and care records.</p>
        </div>
    </div>
    <div class="form-grid">
        <label>Patient code<input id="new-patient-code" name="code" required maxlength="40" value="{{ old('code', $patient->code) }}" placeholder="PAT-001"></label>
        <label>Full name<input name="full_name" required maxlength="150" value="{{ old('full_name', $patient->full_name) }}"></label>
        <label>Date of birth<input type="date" name="birth_date" value="{{ old('birth_date', $patient->birth_date?->format('Y-m-d')) }}"></label>
        <label>Gender<select name="gender"><option value="">Not provided</option>@foreach(['female', 'male', 'other', 'unspecified'] as $gender)<option value="{{ $gender }}" @selected(old('gender', $patient->gender) === $gender)>{{ ucfirst($gender) }}</option>@endforeach</select></label>
    </div>
</section>
<section class="form-section">
    <div class="form-section-heading">
        <div>
            <div class="eyebrow">STEP 2</div>
            <h2>Care team and location</h2>
            <p>Choose the caregiver responsible for follow-up and the patient's room.</p>
        </div>
    </div>
    <div class="form-grid">
        <label>Room<select name="room_id"><option value="">Unassigned</option>@foreach($rooms as $room)<option value="{{ $room->id }}" @selected(old('room_id', $patient->room_id) == $room->id)>Room {{ $room->number }} / {{ $room->name }}</option>@endforeach</select></label>
        <label>Caregiver<select name="caregiver_id"><option value="">Unassigned</option>@foreach($caregivers as $caregiver)<option value="{{ $caregiver->id }}" @selected(old('caregiver_id', $patient->caregiver_id) == $caregiver->id)>{{ $caregiver->name }}</option>@endforeach</select><small class="field-hint">Only active caregiver accounts appear here.</small></label>
    </div>
</section>
<section class="form-section">
    <div class="form-section-heading">
        <div>
            <div class="eyebrow">STEP 3</div>
            <h2>Monitoring hardware</h2>
            <p>Assign one active physical device. Its heartbeat and emergency events will follow this patient.</p>
        </div>
    </div>
    <label class="assignment-choice">Physical monitoring device
        <select name="device_id">
            <option value="">{{ $patient->exists ? 'Keep current hardware assignment' : 'No hardware assignment' }}</option>
            @foreach($devices as $device)
                @php($assignedPatient = $device->currentAssignment?->patient)
                <option value="{{ $device->id }}" @selected(old('device_id', $patient->currentAssignments->first()?->device_id) == $device->id) @disabled($assignedPatient && $assignedPatient->id !== $patient->id)>
                    {{ $device->name }} ({{ $device->code }}) &mdash; {{ $device->type === 'esp32' ? 'Main controller' : 'Camera node' }}{{ $assignedPatient && $assignedPatient->id !== $patient->id ? ' &mdash; assigned to '.$assignedPatient->full_name : '' }}
                </option>
            @endforeach
        </select>
        <small class="field-hint">Devices assigned to another patient stay unavailable until an administrator unassigns them.</small>
        @if($patient->exists && $patient->currentAssignments->isNotEmpty())
            <span class="check"><input type="checkbox" name="unassign_device" value="1" @checked(old('unassign_device'))> <span>Unassign the current hardware when saving</span></span>
        @endif
    </label>
</section>
<section class="form-section">
    <div class="form-section-heading">
        <div>
            <div class="eyebrow">STEP 4</div>
            <h2>Watcher and emergency contact</h2>
            <p>The watcher receives a {{ config('watcher.channel') === 'semaphore' ? 'SMS' : 'Telegram' }} alert only after an administrator or caregiver confirms a sensor-detected fall.</p>
        </div>
    </div>
    <div class="form-grid">
        <label for="patient-watcher-name">Watcher / emergency contact name<input id="patient-watcher-name" name="emergency_contact_name" maxlength="150" autocomplete="off" value="{{ old('emergency_contact_name', $patient->emergency_contact_name) }}" placeholder="Full name of the patient's watcher"></label>
        <label for="patient-watcher-phone">Emergency contact mobile number<input id="patient-watcher-phone" type="tel" name="emergency_contact_phone" maxlength="32" inputmode="tel" aria-describedby="patient-watcher-phone-help" value="{{ old('emergency_contact_phone', $patient->emergency_contact_phone) }}" placeholder="09171234567"><small id="patient-watcher-phone-help" class="field-hint">Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.</small></label>
        <label class="span-2" for="patient-watcher-relationship">Relationship to patient <small>(optional)</small><input id="patient-watcher-relationship" name="emergency_contact_relationship" maxlength="80" value="{{ old('emergency_contact_relationship', $patient->emergency_contact_relationship) }}" placeholder="Spouse, parent, child, guardian, or other"></label>
    </div>
    @if(config('watcher.channel') === 'semaphore')
    <input type="hidden" name="emergency_sms_enabled" value="0">
    <label class="check"><input type="checkbox" name="emergency_sms_enabled" value="1" @checked(old('emergency_sms_enabled', $patient->emergency_sms_enabled))><span>Notify this watcher by SMS when a fall is confirmed.<small class="field-hint">Confirm that the watcher agrees to receive these alerts. Saving this profile does not send a text.</small></span></label>
    @unless(app(\App\Services\WatcherSmsService::class)->isConfigured())
        <div class="notice warning compact" role="note">Watcher details can be saved now. SMS sending will be available after the administrator activates the SMS service.</div>
    @endunless
    @else
        <input type="hidden" name="watcher_telegram_enabled" value="0">
        <label class="check"><input type="checkbox" name="watcher_telegram_enabled" value="1" @checked(old('watcher_telegram_enabled', $patient->watcher_telegram_enabled))><span>Notify this watcher through Telegram when a fall is confirmed.<small class="field-hint">Get the watcher's consent. Save this profile, then use Connect watcher on the patient profile. The watcher must open the private link and press Start. No SMS credits are required.</small></span></label>
        @unless(app(\App\Services\TelegramBotClient::class)->isConfigured())
            <div class="notice warning compact" role="note">Watcher details can be saved now. The administrator must configure the Telegram bot before a watcher can connect.</div>
        @endunless
        @if($patient->watcher_telegram_linked_at)
            <p class="field-hint">A watcher is connected. Changing the watcher name or mobile number will disconnect that account for safety.</p>
            <label class="check"><input type="checkbox" name="replace_watcher_account" value="1" @checked(old('replace_watcher_account', false))><span>Replace with a DIFFERENT Telegram account.<small class="field-hint">Disconnect the current watcher, block pending alerts, and require a private Start link from the new account. Changing the phone number alone cannot choose the Telegram recipient.</small></span></label>
        @endif
    @endif
    @if($patient->emergency_contact || old('emergency_contact'))
        <label>Previous emergency contact notes<input name="emergency_contact" maxlength="255" value="{{ old('emergency_contact', $patient->emergency_contact) }}"><small class="field-hint">Previous contact details are preserved. Enter the watcher's current name and contact details above.</small></label>
    @endif
</section>
<section class="form-section">
    <div class="form-section-heading">
        <div>
            <div class="eyebrow">STEP 5</div>
            <h2>Care notes and status</h2>
            <p>Keep care notes available to the assigned caregiver.</p>
        </div>
    </div>
    <div class="form-grid">
        <label>Patient status<select name="is_active"><option value="1" @selected(old('is_active', $patient->is_active))>Active</option><option value="0" @selected(!old('is_active', $patient->is_active))>Inactive</option></select></label>
        <label class="span-2">Relevant care notes<textarea name="medical_notes" rows="4" maxlength="4000">{{ old('medical_notes', $patient->medical_notes) }}</textarea></label>
    </div>
</section>
