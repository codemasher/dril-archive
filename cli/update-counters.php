<?php
/**
 * Updates the counters for the given archive
 *
 * @created      04.12.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

use codemasher\DrilArchive\DrilArchive;
use codemasher\DrilArchive\DrilArchiveOptions;
use codemasher\DrilArchive\Util;

ini_set('date.timezone', 'UTC');

require_once __DIR__.'/../vendor/autoload.php';


$options = new DrilArchiveOptions;
// HTTPOptions
$options->ca_info                 = realpath(__DIR__.'/../config/cacert.pem'); // https://curl.haxx.se/ca/cacert.pem
$options->user_agent              = 'drilArchive/1.0 +https://github.com/codemasher/dril-archive';
// DrilArchiveOptions
$options->builddir                = __DIR__.'/../.build';
$options->outdir                  = __DIR__.'/../output';
$options->fromCachedApiResponses  = true;
$options->apiToken                = Util::getToken(__DIR__.'/../config', '.env', 'TWITTER_BEARER');


$timelineJSON = realpath(sprintf('%s/../output/%s.json', __DIR__, $options->filename));

// on GitHub actions: clone repo, checkout gh-pages, use previous build
if(isset($_SERVER['GITHUB_ACTIONS'])){
	$timelineJSON = realpath(sprintf('%s/../previous-build/%s.json', __DIR__, $options->filename));
}

(new DrilArchive($options))->updateCounters($timelineJSON);

exit;
