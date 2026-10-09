<?php

namespace App\Exceptions;

use RuntimeException;

/** Safe to display: never contains a token-bearing URL or raw HTTP exception. */
class TelegramPollingException extends RuntimeException
{
    public function __construct(?int $httpStatus = null, public readonly ?string $failureType = null, public readonly ?int $transportError = null)
    {
        parent::__construct($httpStatus === null
            ? 'Telegram connection failed. Check server internet access.'
            : 'Telegram rejected watcher registration (HTTP '.$httpStatus.'). Check bot credentials and ensure no other poller or webhook uses this bot.');
    }
}
