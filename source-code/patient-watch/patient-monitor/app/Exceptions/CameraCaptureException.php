<?php

namespace App\Exceptions;

use RuntimeException;

class CameraCaptureException extends RuntimeException
{
    public function __construct(
        public readonly string $cameraError,
        public readonly bool $retryable = true,
    ) {
        parent::__construct('Camera snapshot request failed: '.$cameraError);
    }
}
