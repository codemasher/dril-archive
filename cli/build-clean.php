<?php
/**
 * Creates a new archive from scratch
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

/*
 * The bearer token from the developer account won't work here. How to get the request token:
 *
 *   - open https://twitter.com/search in a webbrowser (chrome or firefox recommended), ideally in an incognito tab
 *   - open the developer console (press F12)
 *   - type anything in the twitter search box, hit enter
 *   - go to the "network" tab in the dev console and filter the requests for "adaptive.json"
 *   - click that line, a new tab for that request appears
 *   - there, in the "headers" tab, scroll to "request headers" and look for "Authorization: Bearer ..."
 *   - right click that line, select "copy value" and paste it below, should look like: 'Bearer AAAANRILgAAAAAAnNwI...'
 *
 * It seems that the bearer token is valid for several days at least.
 */
$requestToken = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs=1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

/*
 * The guest token is in the same place as the bearer token, just scroll a bit further
 * and look for the "x-guest-token" header and copy that value.
 *
 * It appears that the guest token is valid for about 2 hours.
 */
$guestToken = '1601580511759110145';

// auto fetching the guest token
if(preg_match('/gt=(?<guest_token>\d+);/', file_get_contents('https://twitter.com'), $match)){
	$guestToken = $match['guest_token'];
}

/*
 * The search query
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
 * @see https://help.twitter.com/en/using-twitter/advanced-tweetdeck-features
 *
 * try:
 *   - "@username" timeline including replies
 *   - "@username include:nativeretweets filter:nativeretweets" for RTs only (returns RTs of the past week only)
 *   - "to:username" for @mentions and replies
 */
$query = 'from:dril include:nativeretweets';

$options = new DrilArchiveOptions;
// HTTPOptions
$options->ca_info                 = realpath(__DIR__.'/../config/cacert.pem'); // https://curl.haxx.se/ca/cacert.pem
$options->user_agent              = 'drilArchive/1.0 +https://github.com/codemasher/dril-archive';
// DrilArchiveOptions
$options->builddir                = __DIR__.'/../.build';
$options->outdir                  = __DIR__.'/../output';
$options->fromCachedApiResponses  = true;
$options->fetchFromAdaptiveSearch = true;
$options->apiToken                = Util::getToken(__DIR__.'/../config', '.env', 'TWITTER_BEARER');
$options->adaptiveRequestToken    = $requestToken;
$options->adaptiveGuestToken      = $guestToken;
$options->query                   = $query;
// https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w
$options->drilCSV                 = realpath(__DIR__.'/../.build/dril.csv');


(new DrilArchive($options))->compileDrilTimeline();

exit;
