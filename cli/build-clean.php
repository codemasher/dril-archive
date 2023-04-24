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

/*
 * The dril .csv from:
 *
 * https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w
 */
$options->drilCSV = realpath(__DIR__.'/../.build/dril.csv');

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
$options->query = 'from:dril include:nativeretweets';

/*
 * The bearer token from the developer account won't work here. How to get the request token:
 *
 *   - open https://twitter.com/search in a webbrowser (chrome or firefox recommended)
 *   - open the developer console (press F12)
 *   - type anything in the twitter search box, hit enter
 *   - go to the "network" tab in the dev console and filter the requests for "adaptive.json"
 *   - click that line, a new tab for that request appears
 *   - there, in the "headers" tab, scroll to "request headers" and look for "Authorization: Bearer ..."
 *   - right click that line, select "copy value" and paste it below, should look like: 'Bearer AAAANRILgAAAAAAnNwI...'
 *
 * Update: it seems that the bearer token doesn't change anymore at all.
 *
 * The website search can no longer be used anonymously, so it's recommended to use a throwaway account for this -
 * just in case twitter decides to ban people for using the adaptive search.
 */
$options->adaptiveRequestToken = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs=1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

/*
 * The CSRF token is in the same place as the bearer token, just scroll a bit further
 * and look for the "x-csrf-token" header and copy that value (a 160 character hexadecimal).
 */
$options->adaptiveCsrfToken = 'dec2d...58a1';

/*
 * The value of the "cookie" header
 */
$options->adaptiveCookie = 'guest_id=v1:164172937677196215; ... tweetdeck_version=beta';


(new DrilArchive($options))->compileDrilTimeline();

exit;
