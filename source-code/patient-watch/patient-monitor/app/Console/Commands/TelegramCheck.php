<?php

namespace App\Console\Commands;

use App\Services\TelegramBotClient;
use Illuminate\Console\Command;

class TelegramCheck extends Command
{
    protected $signature = 'telegram:check {--configured : Only check local settings without contacting Telegram}';
    protected $description = 'Validate Telegram bot settings without printing credentials or sending an incident alert';

    public function handle(TelegramBotClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->warn('Telegram setup is incomplete. Set the private bot token and username in patient-monitor/.env.');
            return self::FAILURE;
        }
        if ($this->option('configured')) return self::SUCCESS;
        try {
            $response = $client->request('getMe');
            if (!$response->successful() || $response->json('ok') !== true || $response->json('result.is_bot') !== true
                || (string) $response->json('result.id') !== $client->botId()
                || strcasecmp((string) $response->json('result.username'), (string) config('watcher.telegram_bot_username')) !== 0) {
                $this->error('Bot validation failed. Check that the token and username belong to the same bot.');
                return self::FAILURE;
            }
        } catch (\Throwable $exception) {
            $this->error('Cannot reach Telegram. Check the internet connection and bot settings. Credentials were not printed.');
            return self::FAILURE;
        }
        $this->info('Telegram bot verified. No incident notification was sent.');
        return self::SUCCESS;
    }
}
