<?php
/**
 * Runs an incremental update for an existing archive
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

use codemasher\DrilArchive\DrilArchive;
use codemasher\DrilArchive\DrilArchiveOptions;
use codemasher\DrilArchive\Util;

ini_set('date.timezone', 'UTC');

require_once __DIR__.'/../vendor/autoload.php';

/*
 * The search query
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
 * @see https://help.twitter.com/en/using-twitter/advanced-tweetdeck-features
 */
$query = 'from:dril include:nativeretweets';

// limit the query results to the past x days
$now   = time();
$since = $now - 86400 * 7; // x days, standard API access will probably return only 20 tweets either way
$query = sprintf('%s since:%s until:%s', $query, date('Y-m-d', $since), date('Y-m-d', $now));

$options = new DrilArchiveOptions;
// HTTPOptions
$options->ca_info                 = realpath(__DIR__.'/../config/cacert.pem'); // https://curl.haxx.se/ca/cacert.pem
$options->user_agent              = 'drilArchive/1.0 +https://github.com/codemasher/dril-archive';
// DrilArchiveOptions
$options->builddir                = __DIR__.'/../.build';
$options->outdir                  = __DIR__.'/../output';
$options->fromCachedApiResponses  = true;
$options->fetchFromAPISearch      = true;
$options->apiToken                = Util::getToken(__DIR__.'/../config', '.env', 'TWITTER_BEARER');
$options->query                   = $query;


// we need "/.build/dril.json here" just for local runs (or repairs...)
$timelineJSON = realpath(sprintf('%s/../output/%s.json', __DIR__, $options->filename));

// on GitHub actions: clone repo, checkout gh-pages, use previous build
if(isset($_SERVER['GITHUB_ACTIONS'])){
	$timelineJSON = realpath(sprintf('%s/../previous-build/%s.json', __DIR__, $options->filename));
}

(new DrilArchive($options))->compileDrilTimeline($timelineJSON, true, $since);

exit;
