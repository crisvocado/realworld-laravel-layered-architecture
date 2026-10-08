<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;

class LogcoreLoggerFactory
{
    /**
     * Create a custom Monolog instance for Logcore.
     *
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): LoggerInterface
    {
        $level = $config['level'] ?? env('LOGCORE_MIN_SEVERITY', 'error');
        $handler = new LogcoreHandler($level);

        return new Logger('logcore', [$handler]);
    }
}
