<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

class ConfigureJsonLogging
{
    // Reference: https://github.com/laravel/docs/blob/13.x/logging.md
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new SafeContextProcessor);
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'setFormatter')) {
                $handler->setFormatter(new JsonFormatter);
            }
        }
    }
}
