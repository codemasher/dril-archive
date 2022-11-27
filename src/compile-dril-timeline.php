<?php
/**
 * dril-compile.php
 *
 * @created      22.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use chillerlan\HTTP\Utils\MessageUtil;
use function array_chunk;
use function array_combine;
use function array_fill;
use function array_keys;
use function array_reverse;
use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_decode;
use function md5;
use function mkdir;
use function realpath;
use function sleep;
use function sort;
use function sprintf;
use function str_starts_with;
use const JSON_THROW_ON_ERROR;
use const SORT_NUMERIC;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * @var \Psr\Log\LoggerInterface $logger
 * @var $http \Psr\Http\Client\ClientInterface $http
 */
require_once __DIR__.'/logger.php';
require_once __DIR__.'/http.php';
require_once __DIR__.'/functions.php';

//continue/run from stored API responses, useful if the run gets interrupted for whatever reason
$fromCachedApiResponses = true;

// output file names
$outTimelineJSON = __DIR__.'/../output/dril-timeline.json';
$outUsersJSON    = __DIR__.'/../output/dril-users.json';

// setup file paths
$timelineJSON = realpath(__DIR__.'/../.build/from-dril/c1e557f317aedab4bb23182877876fe7-timeline.json');
$userJSON     = realpath(__DIR__.'/../.build/from-dril/c1e557f317aedab4bb23182877876fe7-users.json');
$drilCSV      = realpath(__DIR__.'/../.build/dril.csv.json');

// the directory to cache API responses in
$cachedir = __DIR__.'/../.build/from-dril';

if(!file_exists($cachedir)){
	mkdir(directory: $cachedir, recursive: true);
}

$cachedir = realpath($cachedir);
$token    = getToken();

// load the json files
$t = loadJSON($timelineJSON, true);
$u = loadJSON($userJSON, true);
$c = loadJSON($drilCSV, true);

$timeline = [];
$retweets = [];
$users    = [];
$csv      = [];
$rtIDs    = [];

foreach($t as $id => $tweet){
	// collect the retweet IDs from the parsed timeline
	if(str_starts_with($tweet['text'], 'RT @')){
		$retweets[]    = $id;
		$timeline[$id] = null;
	}
	// put the already parsed tweets in the output array
	else{
		$timeline[$id] = $tweet;
	}
}

foreach($c as $id => $tweet){
	// collect RT IDs from the CSV
	if(str_starts_with($tweet['text'], 'RT @')){
		$retweets[]    = $id;
		$timeline[$id] = null;
	}
	// collect other tweet IDs from the CSV for a later diff
	else{
		$csv[] = $id;
	}
}


/*
 * RTs are a mess and the messages are always truncated in the fetched RT status, so we'll need to fetch the original tweets too.
 * An RT creates a separate status that is saved as old style retweet "RT @username ...", truncated to 140 characters.
 * Both, v1 and v2 endpoints will only return the truncated text if the RT status id is called.
 * Only the v2 endpoint returns the id of the original tweet that was retweeted.
 */

// we're gonna fetch the metadata for the retweet status from the v2 endpoint first
foreach(array_chunk($retweets, 100) as $ids){

	$v2Params = [
		'ids'          => implode(',', $ids),
		'tweet.fields' => 'author_id,referenced_tweets,conversation_id,created_at',
	];

	$filename = sprintf('%s/meta-v2-tweets-%s.json', $cachedir, md5(implode(',', array_values($v2Params))));

	if($fromCachedApiResponses && file_exists($filename)){
		$response = file_get_contents($filename);
	}
	else{
		$r = httpRequest('https://api.twitter.com/2/tweets', $v2Params);

		if($r->getStatusCode() !== 200){
			$logger->warning('could not fetch tweets from /2/tweets');

			continue;
		}

		$response = $r->getBody()->getContents();

		file_put_contents($filename, $response);
	}

	$json = json_decode(json: $response, flags: JSON_THROW_ON_ERROR);

	foreach($json->data as $tweet){

		if(!isset($tweet->referenced_tweets)){
			$logger->warning(sprintf('does not look like a retweet: "%s"', $tweet->text ?? ''));

			continue;
		}

		$id   = (int)$tweet->referenced_tweets[0]->id;
		$rtID = (int)$tweet->id;
		// create a parsed tweet for the RT status
		$timeline[$rtID] = parseTweet($tweet);
		// save the original tweet id in the RT status
		$timeline[$rtID]['retweeted_status_id'] = $id;
		// to backreference in the next op
		// original tweet id => retweet status id
		$rtIDs[$id] = $rtID;
	}

	$logger->info(sprintf('fetched meta for %s tweets', count($ids)));

	// i don't care for 429 here...
	if(!$fromCachedApiResponses){
		sleep(3);
	}
}


// now fetch the original retweeted tweets
// this is even more of a mess as both, the v1 and v2 endpoints don't return the complete data so we're gonna call both
foreach(array_chunk(array_keys($rtIDs), 100) as $ids){

	$v1Params = [
		'id'                   => implode(',', $ids),
		'trim_user'            => false,
		'map'                  => false,
		'include_ext_alt_text' => true,
		'skip_status'          => true,
		'include_entities'     => true,
	];

	// all the fields! (what a fucking mess)
	$v2Params = [
		'ids'          => implode(',', $ids),
		'expansions'   => 'attachments.poll_ids,attachments.media_keys,author_id,entities.mentions.username,geo.place_id,in_reply_to_user_id,referenced_tweets.id,referenced_tweets.id.author_id',
		'media.fields' => 'duration_ms,height,media_key,preview_image_url,type,url,width,public_metrics,alt_text,variants',
		'place.fields' => 'contained_within,country,country_code,full_name,geo,id,name,place_type',
		'poll.fields'  => 'duration_minutes,end_datetime,id,options,voting_status',
		'tweet.fields' => 'attachments,author_id,conversation_id,created_at,entities,geo,id,in_reply_to_user_id,lang,public_metrics,possibly_sensitive,referenced_tweets,reply_settings,source,text,withheld',
		'user.fields'  => 'created_at,description,entities,id,location,name,pinned_tweet_id,profile_image_url,protected,public_metrics,url,username,verified,withheld',
	];

	$filename1 = sprintf('%s/data-v1-statuses-lookup-%s.json', $cachedir, md5(implode(',', array_values($v1Params))));
	$filename2 = sprintf('%s/data-v2-tweets-%s.json', $cachedir, md5(implode(',', array_values($v2Params))));

	if($fromCachedApiResponses && file_exists($filename1) && file_exists($filename2)){
		$v1Response = file_get_contents($filename1);
		$v2Response = file_get_contents($filename2);
	}
	else{
		$r1 = httpRequest('https://api.twitter.com/1.1/statuses/lookup.json', $v1Params);
		$r2 = httpRequest('https://api.twitter.com/2/tweets', $v2Params);

		if($r1->getStatusCode() !== 200 || $r2->getStatusCode() !== 200){
			$logger->warning('could not fetch tweets from v1 or v2 endpoints');

			continue;
		}

		$v1Response = $r1->getBody()->getContents();
		$v2Response = $r2->getBody()->getContents();

		file_put_contents($filename1, $v1Response);
		file_put_contents($filename2, $v2Response);
	}

	$v1json = json_decode(json: $v1Response, flags: JSON_THROW_ON_ERROR);
	$v2json = json_decode(json: $v2Response, flags: JSON_THROW_ON_ERROR);

	foreach($v1json as $v1Tweet){
		$users[$v1Tweet->user->id] = parseUser($v1Tweet->user);

		unset($v1Tweet->user);

		$timeline[$rtIDs[$v1Tweet->id]]['retweeted_status'] = parseTweet($v1Tweet);
	}

	foreach($v2json->data as $v2Tweet){
		$v2Tweet = parseTweet($v2Tweet);
		$rtID    = $rtIDs[(int)$v2Tweet['id']];

		// @todo: image urls https://twitter.com/<user>/status/<id>>/photo/1
		foreach(['user_id', 'text', 'conversation_id', 'place', 'geo', 'media'] as $field){
			$timeline[$rtID]['retweeted_status'][$field] = $v2Tweet[$field];
		}
	}

	$logger->info(sprintf('fetched data for %s tweets', count($ids)));

	// i don't care for 429 here either...
	if(!$fromCachedApiResponses){
		sleep(3);
	}
}


// fetch the remaining tweets from the csv
foreach(array_chunk($csv, 100) as $ids){

	$v1Params = [
		'id'                   => implode(',', $ids),
		'trim_user'            => false,
		'map'                  => false,
		'include_ext_alt_text' => true,
		'skip_status'          => true,
		'include_entities'     => true,
	];

	$v1Response = httpRequest('https://api.twitter.com/1.1/statuses/lookup.json', $v1Params);

	if($v1Response->getStatusCode() !== 200){
		$logger->warning('could not fetch tweets from v1 endpoint');

		continue;
	}

	$v1json = MessageUtil::decodeJSON($v1Response, false);

	foreach($v1json as $v1Tweet){
		$users[$v1Tweet->user->id] = parseUser($v1Tweet->user);
		unset($user);
		$timeline[$v1Tweet->id]    = parseTweet($v1Tweet);
	}

}

// prepare for output
$sortedTLKeys = array_keys($timeline);
$sortedTL     = array_fill(0, count($sortedTLKeys), null);

sort($sortedTLKeys, SORT_NUMERIC);

$sortedTL = array_combine(array_reverse($sortedTLKeys), $sortedTL);

foreach($timeline as $id => $tweet){
	$sortedTL[$id] = $tweet;

	unset($timeline[$id]);
}

// save
saveJSON($outTimelineJSON, $sortedTL);
saveJSON($outUsersJSON, $users);

// verify readability/decoding
$t = loadJSON($outTimelineJSON, true);
$u = loadJSON($outUsersJSON, true);

$logger->info(sprintf('fetched %d tweets from %d users', count($t), count($u)));
