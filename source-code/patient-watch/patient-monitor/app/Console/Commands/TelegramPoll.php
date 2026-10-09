<?php

namespace App\Console\Commands;

use App\Exceptions\TelegramPollingException;
use App\Services\{TelegramBotClient, TelegramWatcherUpdates};
use Illuminate\Console\Command;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Fetch a single batch for a setup check}';
    protected $description = 'Connect consenting watchers through private Telegram Start links on local or hosted servers';

    public function handle(TelegramBotClient $client, TelegramWatcherUpdates $updates): int
    {
        if (!$client->isConfigured()) {
            $this->warn('Telegram is not configured. Set TELEGRAM_BOT_TOKEN and TELEGRAM_BOT_USERNAME privately, then restart the project services.');
            return self::SUCCESS;
        }
        $this->info('Telegram watcher registration is running. Do not run another poller or webhook for this bot.');
        // Short long-polls tolerate mobile-hotspot connection timeouts. After a
        // failure, use one non-blocking read to recover; never retry alert sends.
        $configuredTimeout = max(0, min(20, (int) config('watcher.telegram_poll_timeout', 5)));
        $pollTimeout = $configuredTimeout;
        if ($configuredTimeout === 0) $this->info('Registration polling uses non-blocking recovery mode.');
        do {
            try {
                $count = $updates->pollOnce($this->option('once') ? 0 : $pollTimeout);
                $pollTimeout = $configuredTimeout;
                if ($count) $this->info('Processed '.$count.' Telegram update(s).');
            } catch (\Throwable $exception) {
                // Exceptions from HTTP and database layers may contain secrets.
                $this->warn($exception instanceof TelegramPollingException
                    ? $exception->getMessage()
                    : 'Telegram polling failed ('.class_basename($exception).'). Check database migrations and local service configuration.');
                if ($exception instanceof TelegramPollingException && $exception->failureType) {
                    $this->warn('Safe diagnostic: '.$exception->failureType.($exception->transportError !== null ? ', cURL '.$exception->transportError : '').'.');
                }
                $pollTimeout = 0;
                if ($this->option('once')) return self::FAILURE;
            }
            if (!$this->option('once')) sleep(2);
        } while (!$this->option('once'));
        return self::SUCCESS;
    }
}
