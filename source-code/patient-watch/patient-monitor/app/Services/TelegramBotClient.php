<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelegramBotClient
{
    public function botId(): string
    {
        return explode(':', trim((string) config('watcher.telegram_bot_token')))[0];
    }

    public function isConfigured(): bool
    {
        return config('watcher.channel') === 'telegram' && (bool) config('watcher.telegram_enabled')
            && preg_match('/^[1-9][0-9]{0,19}:[A-Za-z0-9_-]+$/D', trim((string) config('watcher.telegram_bot_token')))
            && preg_match('/^[A-Za-z0-9_]{5,32}$/D', (string) config('watcher.telegram_bot_username'));
    }

    public function request(string $method, array $payload = [], int $timeout = 10): Response
    {
        // Never log HTTP exceptions: their URL contains the bot token.
        return Http::asJson()->acceptJson()->connectTimeout($method === 'getUpdates' ? 5 : 3)->timeout($timeout)
            ->post('https://api.telegram.org/bot'.trim((string) config('watcher.telegram_bot_token')).'/'.$method, $payload);
    }

    public function sendMessage(string $chatId, string $text): Response
    {
        return $this->request('sendMessage', [
            'chat_id' => $chatId, 'text' => $text, 'protect_content' => true,
            'disable_notification' => false, 'allow_paid_broadcast' => false,
        ]);
    }
}
