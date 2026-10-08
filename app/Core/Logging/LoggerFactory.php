<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Application;
use App\Core\Config\Repository as Config;
use App\Support\LocalTime;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public function __invoke(Application $app, Config $config): LoggerInterface
    {
        $level = self::level((string) $config->get('logging.level', 'info'));
        $file = $app->storagePath('logs/' . $config->get('logging.file', 'app.log'));

        $handler = LogFile::at($file, (int) $config->get('logging.max_files', 14), $level);
        // Multi-line messages, no empty context brackets, and the trace of an exception passed as context — with no
        // secret in any of it (bot tokens in Telegram's URLs, the webhook secret and cron token in request paths), each
        // line after a record's first indented under it, the trace's files told from the app's folder.
        $formatter = new RedactingLineFormatter(null, 'Y-m-d H:i:s', true, true, true);
        $formatter->setBasePath($app->basePath());
        $handler->setFormatter($formatter);

        // The log reads in the shop's own time, as the admin who opens it does; each line of a request names it, and who
        // it was made for.
        $logger = new Logger('app', timezone: LocalTime::zone());
        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new RequestIdProcessor());
        $logger->pushProcessor(new ActorProcessor());

        return $logger;
    }

    /** The configured LOG_LEVEL, whatever its case; a misspelt one logs at Info rather than taking the app down. */
    private static function level(string $name): Level
    {
        foreach (Level::cases() as $level) {
            if (strcasecmp($level->name, $name) === 0) {
                return $level;
            }
        }

        return Level::Info;
    }
}
