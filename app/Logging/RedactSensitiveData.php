<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * Log channel tap (config/logging.php `tap`) that installs the
 * SensitiveDataProcessor on every handler of the channel.
 */
class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $processor = new SensitiveDataProcessor;

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
