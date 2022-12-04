<?php
/**
 * Runs an incremental update for an existing archive
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

use chillerlan\DotEnv\DotEnv;
use codemasher\DrilArchive\DrilArchive;
use codemasher\DrilArchive\DrilArchiveOptions;

ini_set('date.timezone', 'UTC');

require_once __DIR__.'/../vendor/autoload.php';


/*
 * The search query
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
 * @see https://help.twitter.com/en/using-twitter/advanced-tweetdeck-features
 */

$now   = time();
$since = $now - 86400 * 7; // x days, standard API access will probably return only 20 tweets either way
$query = sprintf('from:dril include:nativeretweets since:%s until:%s', date('Y-m-d', $since), date('Y-m-d', $now));


$options = new DrilArchiveOptions;
// HTTPOptions
$options->ca_info                 = realpath(__DIR__.'/../config/cacert.pem'); // https://curl.haxx.se/ca/cacert.pem
$options->user_agent              = 'drilArchive/1.0 +https://github.com/codemasher/dril-archive';
// DrilArchiveOptions
$options->builddir                = __DIR__.'/../.build';
$options->outdir                  = __DIR__.'/../output';
$options->fromCachedApiResponses  = false;
$options->fetchFromAPISearch      = true;
$options->apiToken                = getToken();
$options->query                   = $query;


// we need this one here just for local runs (or repairs...)
$timelineJSON = __DIR__.'/../.build/dril.json';

// on GitHub actions: clone repo, checkout gh-pages, use previous build
if(isset($_SERVER['GITHUB_ACTIONS'])){
	$timelineJSON = realpath(__DIR__.'/../previous-build/dril.json');
}

(new DrilArchive($options))->compileDrilTimeline($timelineJSON, true, $since);


exit;

/**
 * attempt to get a bearer token from the environment.
 */
function getToken():string{

	// get the token from the environment/config
	if(isset($_SERVER['GITHUB_ACTIONS'])){
		return getenv('TWITTER_BEARER');
	}

	// a dotenv instance for the config
	$env = (new DotEnv(__DIR__.'/../config', '.env', false))->load();

	return $env->get('TWITTER_BEARER');
}
