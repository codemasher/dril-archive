<?php
/**
 * run.php
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

use chillerlan\DotEnv\DotEnv;
use codemasher\DrilArchive\DrilArchive;
use codemasher\DrilArchive\DrilArchiveOptions;

ini_set('date.timezone', 'UTC');

require_once __DIR__.'/../vendor/autoload.php';

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

$now = time();

$options = new DrilArchiveOptions([
	// HTTPOptions
	'ca_info'                 => realpath(__DIR__.'/../config/cacert.pem'), // https://curl.haxx.se/ca/cacert.pem
	'user_agent'              => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)',
	// DrilArchiveOptions
	'builddir'                => __DIR__.'/../.build',
	'outdir'                  => __DIR__.'/../output',
	'fromCachedApiResponses'  => true,
	'apiToken'                => getToken(),
	'fetchFromAdaptiveSearch' => false,
#	'adaptiveRequestToken'    => 'AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA',
#	'adaptiveGuestToken'      => '1598800173827858440',
#	'query'                   => 'from:dril include:nativeretweets',
	'fetchFromAPISearch'      => true,
	'query'                   => sprintf('from:dril include:nativeretweets since:%s until:%s', date('Y-m-d', ($now - 86400 * 30)), date('Y-m-d', $now)),
#	'drilCSV'                 => realpath(__DIR__.'/../.build/dril.csv'), // https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w
#	'' => '',
]);

$timelineJSON = null;

// on GitHub actions: clone repo, checkout gh-pages, use previous build
if(isset($_SERVER['GITHUB_ACTIONS'])){
	$timelineJSON = realpath(__DIR__.'/../previous-build/dril-timeline.json');
}

(new DrilArchive($options))->compileDrilTimeline($timelineJSON, false);
