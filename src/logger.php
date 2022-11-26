<?php
/**
 * logger.php
 *
 * @created      25.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;

require_once __DIR__.'/../vendor/autoload.php';

// a log handler for STDOUT (or STDERR if you prefer)
$logHandler = (new StreamHandler('php://stdout', LogLevel::INFO))
	->setFormatter((new LineFormatter(null, 'Y-m-d H:i:s', true, true))->setJsonPrettyPrint(true));

// invoke the worker instances
$logger = new Logger('log', [$logHandler]); // PSR-3
