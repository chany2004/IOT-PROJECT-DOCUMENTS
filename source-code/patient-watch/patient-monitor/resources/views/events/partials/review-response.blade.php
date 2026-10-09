<section class="review-card review-response" aria-labelledby="review-response-title">
    <header class="review-card-header">
        <div class="review-section-heading">
            <span class="review-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 4H4v16h16v-8M9 15l1-4L19 2l3 3-9 9-4 1Z"/></svg></span>
            <div><span class="review-eyebrow">CAREGIVER FOLLOW-UP</span><h2 id="review-response-title">Record your response</h2></div>
        </div>
    </header>
    @if($isClosed)
        <div class="review-closed"><span class="review-closed-icon" aria-hidden="true">&#10003;</span><h3>This incident is closed</h3><p>Your response history and camera evidence remain available for review.</p><a href="#event-response-history">View response history <span aria-hidden="true">&rarr;</span></a></div>
    @else
        <form class="review-response-form" method="post" action="{{ route('events.update', $event) }}" data-review-response>
            @csrf @method('PATCH')
            <p class="review-form-intro">Document the patient check and the action you took.</p>
            @if($event->verification_status === 'verifying')<div class="notice warning compact">The camera is still verifying. Check the patient now; a pending image is not a reason to dismiss this incident.</div>@endif
            <div class="review-form-fields">
                <label for="review-status">Response status<select id="review-status" name="status">
                    <option value="acknowledged" @selected(old('status', 'acknowledged') === 'acknowledged')>Acknowledged — checking patient</option>
                    <option value="resolved" @selected(old('status') === 'resolved')>Resolved — assistance completed</option>
                    <option value="false_alarm" @selected(old('status') === 'false_alarm')>False alarm — personally reviewed and ruled out</option>
                </select></label>
                <label for="review-outcome">Caregiver outcome<select id="review-outcome" name="caregiver_outcome">
                    <option value="">No final outcome yet</option>
                    <option value="confirmed_fall" @selected(old('caregiver_outcome', $event->caregiver_outcome) === 'confirmed_fall')>Fall confirmed by administrator / caregiver</option>
                    <option value="escalated" @selected(old('caregiver_outcome', $event->caregiver_outcome) === 'escalated')>Emergency escalated</option>
                    <option value="patient_safe" @selected(old('caregiver_outcome', $event->caregiver_outcome) === 'patient_safe')>Patient checked and safe</option>
                </select></label>
            </div>
            @if(!$isManualEmergency || config('watcher.channel') !== 'semaphore')
                <p class="review-form-intro">Selecting <strong>Fall confirmed</strong> and saving queues one {{ config('watcher.channel') === 'semaphore' ? 'SMS' : 'Telegram notification' }} to the patient's watcher when their connection and notification service are ready. Acknowledging an alert alone does not notify the watcher.</p>
            @endif
            <label for="review-note">Incident notes <span class="review-required">Required</span><textarea id="review-note" name="note" rows="4" required minlength="3" maxlength="4000" aria-describedby="review-note-help" placeholder="Describe the patient's condition, your observations, and the assistance provided.">{{ old('note') }}</textarea></label>
            <div class="review-note-meta"><span id="review-note-help">Include what you observed and what action was taken.</span><span data-review-note-count>{{ mb_strlen(old('note', '')) }} / 4,000</span></div>
            <div class="review-form-footer"><p>Your response is saved in this incident's history.</p><button class="button review-save" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg><span>Save caregiver response</span></button></div>
        </form>
    @endif
</section>
