@php
    $telegramConfigured = app(\App\Services\TelegramBotClient::class)->isConfigured();
    $telegramConnected = app(\App\Services\WatcherTelegramService::class)->isConnected($patient);
    $privateLink = session('watcher_telegram_links.'.$patient->id) ?? session('telegram_link');
    $invitation = $patient->watcherTelegramLink;
    $replacingTelegramAccount = (bool) $invitation?->excluded_chat_hash;
    $validPrivateLink = !$telegramConnected && $telegramConfigured
        && $patient->is_active && $patient->watcher_telegram_enabled && $patient->emergency_contact_name
        && is_array($privateLink) && (int) ($privateLink['patient_id'] ?? 0) === $patient->id
        && $invitation && !$invitation->consumed_at && $invitation->expires_at->isFuture()
        && $invitation->created_by === auth()->id()
        && $invitation->bot_id === app(\App\Services\TelegramBotClient::class)->botId()
        && hash_equals($invitation->token_hash, $privateLink['token_hash'] ?? '');
    $startCommand = null;
    if ($validPrivateLink) {
        parse_str(parse_url($privateLink['url'], PHP_URL_QUERY) ?? '', $inviteQuery);
        $startCommand = '/start '.($inviteQuery['start'] ?? '');
    }
@endphp
<div class="divider"></div>
<h3 id="watcher-telegram">Telegram connection</h3>
<p class="small-text muted">Alerts go to a private Telegram chat after care staff confirm a fall. Both the web server and watcher need internet. Telegram alerts are not SMS.</p>
<p class="small-text muted">Changing a mobile number does not select a Telegram recipient. For a different Telegram account, use <strong>Change Telegram account</strong>, then connect from the new account.</p>
@if($telegramConnected)
    <dl class="details">
        <dt>Connection</dt><dd>Watcher connected{{ $patient->watcher_telegram_username ? ' (@'.$patient->watcher_telegram_username.')' : '' }}</dd>
        <dt>Connected on</dt><dd><x-time :value="$patient->watcher_telegram_linked_at"/></dd>
    </dl>
    <p class="small-text muted">Ask the watcher to keep Telegram notifications enabled. They can send /stop to the bot to disconnect.</p>
    <form method="post" action="{{ route('patients.watcher-telegram.connect', $patient) }}" class="form-actions">
        @csrf
        <input type="hidden" name="replace_account" value="1">
        <button class="button small" type="submit">Change Telegram account</button>
    </form>
    <p class="small-text muted">Changing accounts disconnects the previous watcher immediately and creates a private link for a different account. Pending alerts are blocked until reviewed; messages already sent cannot be recalled.</p>
    <form method="post" action="{{ route('patients.watcher-telegram.disconnect', $patient) }}" class="form-actions">
        @csrf @method('DELETE')
        <button class="button subtle small" type="submit">Disconnect watcher</button>
    </form>
@else
    <p class="small-text muted"><strong>Not connected yet.</strong> Save the watcher name and enable Telegram alerts before creating a connection link.</p>
    @if(!$telegramConfigured)
        <div class="notice warning compact" role="note">Telegram bot setup is incomplete. The administrator must add the private bot token and username to the server configuration.</div>
    @elseif($patient->is_active && $patient->watcher_telegram_enabled && $patient->emergency_contact_name)
        @if($replacingTelegramAccount)
            <div class="notice warning compact" role="status">Previous account disconnected. Switch to the NEW Telegram account before pressing Start. The replacement link will not accept the previous account.</div>
        @endif
        @if($invitation && !$invitation->consumed_at && $invitation->expires_at->isPast())
            <div class="notice warning compact" role="status">The previous private link expired. Create a new link below, then open it and send its private Start command to connect the watcher.</div>
        @endif
        <form method="post" action="{{ route('patients.watcher-telegram.connect', $patient) }}" class="form-actions">
            @csrf
            <button class="button small" type="submit">{{ $replacingTelegramAccount ? 'Connect new Telegram account' : 'Connect watcher' }} &mdash; create private link</button>
        </form>
    @endif
    @if($validPrivateLink)
        <div class="notice info compact" data-watcher-connect data-status-url="{{ route('patients.watcher-telegram.status', $patient) }}">
            <strong>Share this link privately with the intended watcher.</strong>
            <p>Open it using the watcher's own Telegram account and press <strong>Start</strong> in <strong>&#64;{{ config('watcher.telegram_bot_username') }}</strong>. It expires in 15 minutes and works once. Creating another link invalidates this one.</p>
            @if($replacingTelegramAccount)
                <p><strong>Use the NEW account.</strong> Switch accounts in Telegram first. If the bot says this is the previous account, the link remains usable by the new account until it expires.</p>
            @endif
            <label>Private connection link<input type="text" readonly value="{{ $privateLink['url'] }}" aria-label="Private Telegram connection link" data-private-link></label>
            <div class="form-actions">
                <a class="button small" href="{{ $privateLink['url'] }}" target="_blank" rel="noopener noreferrer">Open in Telegram</a>
                <button class="button subtle small" type="button" data-copy-target="[data-private-link]">Copy private link</button>
            </div>
            <details><summary>Pressed Start but still not connected?</summary>
                <p>In the same bot chat, copy and send this exact command as a message. Do not share it publicly.</p>
                <label>Private Start command<input type="text" readonly value="{{ $startCommand }}" aria-label="Private Telegram Start command" data-start-command></label>
                <button class="button subtle small" type="button" data-copy-target="[data-start-command]">Copy Start command</button>
            </details>
            <p class="small-text" data-copy-status role="status" aria-live="polite"></p>
            <p class="small-text" data-connection-status role="status" aria-live="polite">Waiting for the watcher to press Start. This page checks the connection automatically.</p>
        </div>
    @endif
@endif
