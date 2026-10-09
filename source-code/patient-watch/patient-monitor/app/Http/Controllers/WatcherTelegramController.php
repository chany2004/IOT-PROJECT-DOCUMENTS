<?php

namespace App\Http\Controllers;

use App\Models\{Patient, WatcherTelegramLink};
use App\Services\{TelegramBotClient, WatcherTelegramService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WatcherTelegramController extends Controller
{
    public function status(Request $request, Patient $patient, TelegramBotClient $client, WatcherTelegramService $service)
    {
        $this->authorizePatient($request, $patient);
        return response()->json([
            'connected' => $service->isConnected($patient),
            'ready' => $service->blockedReason($patient) === null,
            'reason' => $service->blockedReason($patient),
            'invitation_active' => $patient->watcherTelegramLink()->where('bot_id', $client->botId())
                ->whereNull('consumed_at')->where('expires_at', '>', now())->exists(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function connect(Request $request, Patient $patient, TelegramBotClient $client, WatcherTelegramService $service)
    {
        $this->authorizePatient($request, $patient);
        $request->validate(['replace_account' => ['sometimes', 'boolean']]);
        $replacingAccount = $request->boolean('replace_account');
        if (!$client->isConfigured()) {
            throw ValidationException::withMessages(['telegram' => 'The administrator must configure the Telegram bot first.']);
        }
        $token = Str::random(48);
        $link = $service->withConnectionLock($patient->id, fn () => DB::transaction(function () use ($request, $patient, $client, $service, $token, $replacingAccount) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            if (!$patient->is_active || !$patient->watcher_telegram_enabled || !$patient->emergency_contact_name) {
                throw ValidationException::withMessages(['telegram' => 'Save the watcher name and enable Telegram alerts on an active patient profile first.']);
            }
            if (!$replacingAccount && $patient->watcher_telegram_linked_at && $patient->watcher_telegram_bot_id === $client->botId()) {
                throw ValidationException::withMessages(['telegram' => 'Disconnect the existing watcher before linking a replacement.']);
            }
            if ($replacingAccount && $patient->watcher_telegram_chat_id) {
                $service->disconnect($patient, true, $request->user());
            }
            // Keep the previous-account exclusion when an unclaimed replacement
            // invitation expires or is regenerated. Never expose the old chat ID.
            $excludedHash = WatcherTelegramLink::where('patient_id', $patient->id)->value('excluded_chat_hash');
            return WatcherTelegramLink::updateOrCreate(['patient_id' => $patient->id], [
                'created_by' => $request->user()->id, 'token_hash' => hash('sha256', $token),
                'bot_id' => $client->botId(), 'expires_at' => now()->addMinutes(15), 'consumed_at' => null,
                'excluded_chat_hash' => $excludedHash,
            ]);
        }));
        $privateLink = [
            'patient_id' => $patient->id,
            'url' => 'https://t.me/'.config('watcher.telegram_bot_username').'?start=pw_'.$token,
            'token_hash' => $link->token_hash,
        ];
        // Keep the invitation in its creator's authenticated session until it
        // expires, so refreshing the profile does not lose the Start link.
        $request->session()->put('watcher_telegram_links.'.$patient->id, $privateLink);
        return redirect()->route('patients.show', $patient)->with('telegram_link', $privateLink)
            ->with('success', $replacingAccount
                ? 'Previous watcher disconnected. Open this new private link using the NEW Telegram account and press Start. Pending alerts require review before retrying.'
                : 'Private watcher link created. Share it only with the intended watcher; it expires in 15 minutes.');
    }

    public function disconnect(Request $request, Patient $patient, WatcherTelegramService $service)
    {
        $this->authorizePatient($request, $patient);
        $service->withConnectionLock($patient->id, fn () => DB::transaction(function () use ($patient, $service) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $service->disconnect($patient);
        }));
        $request->session()->forget('watcher_telegram_links.'.$patient->id);
        return redirect()->route('patients.show', $patient)->with('success', 'Watcher disconnected. No Telegram alert can be sent until a watcher connects again.');
    }

    private function authorizePatient(Request $request, Patient $patient): void
    {
        abort_unless(Patient::visibleTo($request->user())->whereKey($patient->id)->exists(), 404);
    }
}
