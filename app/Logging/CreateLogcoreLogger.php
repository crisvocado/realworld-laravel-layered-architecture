<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Monolog\Logger;

class CreateLogcoreLogger
{
    /**
     * Create a custom Monolog instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $levelValue = $config['level'] ?? env('LOGCORE_MIN_SEVERITY', 'error');

        $level = Level::Error;
        if ($levelValue instanceof Level) {
            $level = $levelValue;
        } elseif (is_string($levelValue)) {
            $level = Level::fromName(ucfirst(strtolower($levelValue)));
        } elseif (is_int($levelValue)) {
            $level = Level::tryFrom($levelValue) ?? Level::Error;
        }

        $handler = new LogcoreHandler(level: $level);

        $logger = new Logger('logcore');
        $logger->pushHandler($handler);

        return $logger;
    }
}
