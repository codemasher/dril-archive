<?php
/**
 * Twitter timeline backup
 *
 * @see https://github.com/pauldotknopf/twitter-dump
 *
 * @created      17.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function md5;
use function mkdir;
use function print_r;
use function realpath;
use function sleep;
use function sprintf;
use function str_starts_with;
use const JSON_THROW_ON_ERROR;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * @var \Psr\Log\LoggerInterface $logger
 * @var $http \Psr\Http\Client\ClientInterface $http
 */
require_once __DIR__.'/logger.php';
require_once __DIR__.'/http.php';
require_once __DIR__.'/functions.php';

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
 */
$requestToken = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs=1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

/*
 * The search query
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
 * @see https://help.twitter.com/en/using-twitter/advanced-tweetdeck-features
 *
 * try:
 *   - "@username" timeline including replies
 *   - "@username include:nativeretweets filter:nativeretweets" for RTs (returns RTs of the past week only)
 *   - "to:username" for @mentions and replies
 */
$query = 'from:dril';

/*
 * continue/run from stored responses, useful if the run gets interrupted for whatever reason
 */
$fromCachedApiResponses = true;

/*
 * the storage path for the raw responses, a different directory per query is recommended
 */
$dir = __DIR__.'/../.build/from-dril';

/* ==================== stop editing here ===================== */

// create the cache dir in case it doesn't exist
if(!file_exists($dir)){
	mkdir(directory: $dir, recursive: true);
}

$dir = realpath($dir);

// get the bearer token
$token = getToken($requestToken);

// fetch data
[$timeline, $users] = getTimeline($query, $fromCachedApiResponses);

// save the output
$timelineJSON = sprintf('%s/%s-timeline.json', $dir, md5($query));
$userJSON     = sprintf('%s/%s-users.json', $dir, md5($query));

saveJSON($timelineJSON, $timeline);
saveJSON($userJSON, $users);

$logger->info(sprintf('timeline data for "%s" saved in: %s', $query, realpath($timelineJSON))); ;
$logger->info(sprintf('user data saved in: %s', realpath($userJSON)));

// verify readability/decoding
$t = loadJSON($timelineJSON, true);
$u = loadJSON($userJSON, true);

$logger->info(sprintf('fetched %d tweets from %d users', count($t), count($u)));


/* ===================== here be dragons ====================== */

/**
 * retrieves the timeline for the given query and parese the response data
 */
function getTimeline(string $query, bool $fromCachedApiResponses = false):array{
	global $logger, $dir;

	$tweets     = [];
	$users      = [];
	$timeline   = [];
	$lastCursor = '';
	$count      = 0;

	while(true){
		// temp filename
		$filename = sprintf('%s/%s-%d.json', $dir, md5($query), $count);

		// read from file...
		if($fromCachedApiResponses && file_exists($filename)){
			$responseJSON = file_get_contents($filename);
		}
		// ... or fetch from source
		else{
			$response = search($query, $lastCursor);
			$status   = $response->getStatusCode();

			// rate limit hit (doesn't seem to happen?)
			if($status === 429){
				$logger->warning('too many requests: '.print_r($response->getHeaders(), true));
				// just sleep for a bit
				sleep(10);

				continue;
			}
			elseif($status !== 200){
				$logger->error(sprintf('http error %d, %s', $status, $response->getReasonPhrase()));

				break;
			}

			$responseJSON = $response->getBody()->getContents();
			// save the response
			file_put_contents($filename, $responseJSON);
		}

		if(!parseResponse($responseJSON, $tweets, $users, $timeline, $lastCursor)){
			break;
		}

		$logger->info(sprintf('[%s] fetched data for "%s", cursor: %s', $count, $query, $lastCursor));

		$count++;

		if(empty($lastCursor)){
			break;
		}

		// try not to hammer
		if(!$fromCachedApiResponses){
			sleep(2);
		}
	}

	foreach($timeline as $id => &$v){
		$tweet = $tweets[$id];

		// embed quoted tweets
		if($tweet['quoted_status_id'] !== null && isset($tweets[$tweet['quoted_status_id']])){
			$tweet['quoted_status'] = $tweets[$tweet['quoted_status_id']];
		}

		$v = $tweet;
	}

	return [$timeline, $users];
}

/**
 * parse the API response and fill the data arrays (passed by reference)
 */
function parseResponse(string $response, array &$tweets, array &$users, array &$timeline, string &$cursor):bool{

	try{
		$json = json_decode(json: $response, flags: JSON_THROW_ON_ERROR);
	}
	catch(Throwable $e){
		return false;
	}

	if(!isset($json->globalObjects->tweets, $json->globalObjects->users, $json->timeline->instructions)){
		return false;
	}

	if(empty((array)$json->globalObjects->tweets)){
		return false;
	}

	foreach($json->globalObjects->tweets as $tweet){
		$tweets[$tweet->id_str] = parseTweet($tweet);
	}

	foreach($json->globalObjects->users as $user){
		$users[$user->id_str] = parseUser($user);
	}

	foreach($json->timeline->instructions as $i){

		if(isset($i->addEntries->entries)){

			foreach($i->addEntries->entries as $instruction){

				if(str_starts_with($instruction->entryId, 'sq-I-t')){
					$timeline[$instruction->content->item->content->tweet->id] = null;
				}
				elseif($instruction->entryId === 'sq-cursor-bottom'){
					$cursor = $instruction->content->operation->cursor->value;
				}

			}

		}
		elseif(isset($i->replaceEntry->entryIdToReplace) && $i->replaceEntry->entryIdToReplace === 'sq-cursor-bottom'){
			$cursor = $i->replaceEntry->entry->content->operation->cursor->value;
		}
		else{
			$cursor = '';
		}
	}

	return true;
}

/**
 * fetch data from the adaptive search API
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/introduction
 */
function search(string $query, string $cursor = null):ResponseInterface{
	// the query parameters from the call to https://twitter.com/i/api/2/search/adaptive.json in original order
	$params = [
		'include_profile_interstitial_type'    => '1',
		'include_blocking'                     => '1',
		'include_blocked_by'                   => '1',
		'include_followed_by'                  => '1',
		'include_want_retweets'                => '1',
		'include_mute_edge'                    => '1',
		'include_can_dm'                       => '1',
		'include_can_media_tag'                => '1',
		'include_ext_has_nft_avatar'           => '1',
		'include_ext_is_blue_verified'         => '1',
		'skip_status'                          => '1',
		'cards_platform'                       => 'Web-12',
		'include_cards'                        => '1',
		'include_ext_alt_text'                 => 'true',
		'include_ext_limited_action_results'   => 'false',
		'include_quote_count'                  => 'true',
		'include_reply_count'                  => '1',
		'tweet_mode'                           => 'extended',
		'include_ext_collab_control'           => 'true',
		'include_entities'                     => 'true',
		'include_user_entities'                => 'true',
		'include_ext_media_color'              => 'false',
		'include_ext_media_availability'       => 'true',
		'include_ext_sensitive_media_warning'  => 'true',
		'include_ext_trusted_friends_metadata' => 'true',
		'send_error_codes'                     => 'true',
		'simple_quoted_tweet'                  => 'true',
		'q'                                    => $query,
#		'social_filter'                        =>'searcher_follows', // @todo
		'tweet_search_mode'                    => 'live',
		'count'                                => '100',
		'query_source'                         => 'typed_query',
		'cursor'                               => $cursor,
		'pc'                                   => '1',
		'spelling_corrections'                 => '1',
		'include_ext_edit_control'             => 'true',
		'ext'                                  => 'mediaStats,highlightedLabel,hasNftAvatar,voiceInfo,enrichments,superFollowMetadata,unmentionInfo,editControl,collab_control,vibe',
	];

	// remove the cursor parameter if it's empty
	if(empty($params['cursor'])){
		unset($params['cursor']);
	}

	return httpRequest('https://api.twitter.com/2/search/adaptive.json', $params);
}
