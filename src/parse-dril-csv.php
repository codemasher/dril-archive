<?php
/**
 * parse-dril-csv.php
 *
 * @see https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w (every wint (@dril) tweet)
 * @see https://gist.github.com/codemasher/d921cab21c3e684e6bb69219da900b4e (dril's entire timeline, fetched via the unofficial search API)
 * @see https://gist.github.com/codemasher/67ba24cee88029a3278c87ff9a0095ba (Fetch your twitter timeline via the unofficial adaptive search API)
 *
 * @created      20.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use Exception;
use function array_column;
use function array_combine;
use function array_diff_key;
use function array_shift;
use function count;
use function fclose;
use function feof;
use function fgetcsv;
use function file_exists;
use function fopen;
use function ini_set;
use function intval;
use function realpath;
use function sprintf;
use function str_replace;
use function strtotime;
use function trim;

/**
 * @var \Psr\Log\LoggerInterface $logger
 */
require_once __DIR__.'/logger.php';
require_once __DIR__.'/functions.php';

// the path to the dril .csv downloaded from google docs
// https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w
$drilCSV  = realpath(__DIR__.'/../.build/dril.csv');

// the path to the json file from the gist or fetched via timeline.php
$drilJSON = realpath(__DIR__.'/../.build/from-dril/c1e557f317aedab4bb23182877876fe7-timeline.json');

// on GitHub actions: clone repo, checkout gh-pages, use previous build
if(isset($_SERVER['GITHUB_ACTIONS'])){
	$drilJSON = realpath(__DIR__.'/../previous-build/dril-timeline.json');
}

// the output file path
$output   = __DIR__.'/../.build/dril.csv.json';

if(!file_exists($drilCSV) || !file_exists($drilJSON)){
	throw new Exception('cannot open source files');
}

// the fields defined in the .csv
$fields = [
	'name', // always "wint (@dril)"
	'text',
	'date',
	'time',
	'retweets',
	'likes',
	'link',
	'type', // Tweet, Reply, ReTweet
	'image', // No, Yes
	'video', // No, Yes
	'ad',
	'description',
	'location', // always "big burbank"
	'language',
	'source',
];

// fields to unset after parse
$unsetFields = [
	'name',
	'date',
	'time',
	'link',
	'ad',
	'description',
	'location',
	'source',
];

ini_set('date.timezone', 'UTC');

$fh     = fopen($drilCSV, 'r');
$tweets = [];

if(!$fh){
	throw new Exception('could not create file handle');
}

while(!feof($fh)){
	$data = fgetcsv($fh);

	if($data === false){
		break;
	}

	$tweet = array_combine($fields, $data);

	$tweet['user_id']        = 16298441;
	$tweet['id']             = intval(str_replace('https://twitter.com/dril/status/', '', trim($tweet['link'])));
	$tweet['created_at']     = strtotime($tweet['date'].' '.$tweet['time']);
	$tweet['retweet_count']  = intval($tweet['retweets']);
	$tweet['favorite_count'] = intval($tweet['likes']);
	$tweet['image']          = $tweet['image'] === 'Yes';
	$tweet['video']          = $tweet['video'] === 'Yes';

	foreach($unsetFields as $field){
		unset($tweet[$field]);
	}

	$tweets[] = $tweet;
}

fclose($fh);

$logger->info(sprintf('parsed %d tweets from %s', count($tweets), $drilCSV));

// remove the first element (header/description)
array_shift($tweets);

// add tweet IDs as array keys (array_shift would cause re-numbering from 0)
$tweets = array_combine(array_column($tweets, 'id'), $tweets);

// now open the json file archived from the search API
$drilTL = loadJSON($drilJSON, true);

// unset all tweets that we have already archived with metadata
$diff = array_diff_key($tweets, $drilTL);

// save the remaining tweets to json
saveJSON($output, $diff);
