<?php
/**
 * functions.php
 *
 * @created      19.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function sprintf;
use function str_replace;
use function strtotime;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * load a JSON string from file into an array or object
 */
function loadJSON(string $filepath, bool $associative = false):mixed{
	return json_decode(json: file_get_contents($filepath), associative: $associative, flags: JSON_THROW_ON_ERROR);
}

/**
 * save an array or object to a JSON file
 */
function saveJSON(string $filepath, array|object $data):void{
	$jsonFlags = JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT;

	file_put_contents($filepath, json_encode($data, $jsonFlags));
}

/**
 * parse/clean/flatten a tweet object
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/data-dictionary/object-model/tweet
 */
function parseTweet(object $tweet):array{
	$text       = $tweet->full_text ?? $tweet->text ?? '';
	$mediaItems = [];

	foreach($tweet->entities->urls ?? [] as $entity){
		$text = str_replace($entity->url, $entity->expanded_url ?? $entity->url ?? '', $text);
	}

	foreach($tweet->entities->media ?? [] as $media){
		// we'll just remove the shortened media URL as it is of no use
		$text         = str_replace($media->url, '', $text);
		$mediaItems[] = parseMedia($media);
	}

	return [
		'id'                      => (int)$tweet->id,
		'user_id'                 => (int)($tweet->user_id ?? $tweet->author_id ?? $tweet->user->id ?? 0),
		'user'                    => null, // isset($tweet->user) ? parseUser($tweet->user) : null,
		'created_at'              => strtotime($tweet->created_at),
		'text'                    => $text,
		'source'                  => $tweet->source ?? null,
		'retweet_count'           => (int)($tweet->retweet_count ?? $tweet->public_metrics->retweet_count ?? 0),
		'favorite_count'          => (int)($tweet->favorite_count ?? $tweet->public_metrics->like_count ?? 0),
		'reply_count'             => (int)($tweet->reply_count ?? $tweet->public_metrics->reply_count ?? 0),
		'quote_count'             => (int)($tweet->quote_count ?? $tweet->public_metrics->quote_count ?? 0),
		'favorited'               => $tweet->favorited ?? false,
		'retweeted'               => $tweet->retweeted ?? false,
		'possibly_sensitive'      => $tweet->possibly_sensitive ?? false,
		'in_reply_to_status_id'   => $tweet->in_reply_to_status_id ?? null,
		'in_reply_to_user_id'     => $tweet->in_reply_to_user_id ?? null,
		'in_reply_to_screen_name' => $tweet->in_reply_to_screen_name ?? null,
		'is_quote_status'         => $tweet->is_quote_status ?? false,
		'quoted_status_id'        => $tweet->quoted_status_id ?? null,
		'quoted_status'           => null,
		'retweeted_status_id'     => $tweet->retweeted_status_id ?? null,
		'retweeted_status'        => null,
		'self_thread'             => $tweet->self_thread->id ?? null,
		'conversation_id'         => $tweet->conversation_id ?? null,
		'place'                   => $tweet->place ?? null,
		'coordinates'             => $tweet->coordinates ?? null,
		'geo'                     => $tweet->geo ?? null,
		'media'                   => $mediaItems,
	];
}

/**
 * parse/clean/flatten a user object
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/data-dictionary/object-model/user
 */
function parseUser(object $user):array{

	foreach(['name', 'description', 'location', 'url'] as $var){
		${$var} = preg_replace('/\s+/', ' ', $user->{$var} ?? '');
	}

	foreach($user->entities->description->urls ?? [] as $entity){
		$description = str_replace($entity->url, $entity->expanded_url ?? $entity->url ?? '', $description);
	}

	foreach($user->entities->url->urls ?? [] as $entity){
		$url = str_replace($entity->url, $entity->expanded_url ?? $entity->url ?? '', $url);
	}

	$screenName      = $user->screen_name ?? $user->username;
	$ptofile_image_s = $user->profile_image_url_https ?? $user->profile_image_url ?? '';
	$profile_image   = str_replace('_normal.', '.', $ptofile_image_s);
	$profile_banner  = $user->profile_banner_url ?? '';

	return [
		'id'              => $user->id,
		'screen_name'     => $screenName,
		'name'            => $name,
		'description'     => $description,
		'location'        => $location,
		'url'             => $url,
		'followers_count' => $user->followers_count ?? $user->public_metrics->followers_count ?? 0,
		'friends_count'   => $user->friends_count ?? $user->public_metrics->following_count ?? 0,
		'statuses_count'  => $user->statuses_count ?? $user->public_metrics->tweet_count ?? 0,
		'favourites_count' => $user->favourites_count ?? 0,
		'created_at'      => strtotime($user->created_at),
		'protected'       => (bool)($user->protected ?? false),
		'verified'        => (bool)($user->verified ?? false),
		'muting'          => (bool)($user->muting ?? false),
		'blocking'        => (bool)($user->blocking ?? false),
		'blocked_by'      => (bool)($user->blocked_by ?? false),
		'is_cryptobro'    => $user->ext_has_nft_avatar ?? false,
		'clown_emoji'     => $user->ext_is_blue_verified ?? false,
		'profile_image'   => $profile_image,
		'profile_image_s' => $ptofile_image_s,
		'profile_banner'  => $profile_banner,
	];
}

/**
 * @see https://developer.twitter.com/en/docs/twitter-api/data-dictionary/object-model/media
 */
function parseMedia(object $media):array{
	return [
		'id'                 => $media->id,
		'media_key'          => $media->media_key ?? null,
		'source_user_id'     => $media->source_user_id ?? null,
		'type'               => $media->type,
		'url'                => $media->media_url_https ?? $media->media_url,
		'alt_text'           => $media->ext_alt_text ?? '',
		'possibly_sensitive' => $tweet->ext_sensitive_media_warning ?? null,
		'width'              => $media->original_info->width ?? null,
		'height'             => $media->original_info->height ?? null,
		'variants'           => $media->video_info->variants ?? null,
	];
}

/**
 * create <a> links for URLs, hastags and screen names
 */
function parseLinks(string $tweetText):string{

	// link URLs
	$tweetText = preg_replace_callback(
		'#(https?://\S+)#i',
		fn(array $m):string => sprintf('<a href="%1$s" target="_blank">%1$s</a>', $m[0]),
		$tweetText
	);

	// hashtags
	$tweetText = preg_replace_callback(
		'/(#[\w_]+)/i',
		fn(array $m):string => sprintf('<a href="https://twitter.com/search?q=%s" target="_blank">%s</a>', rawurlencode($m[0]), $m[0]),
		$tweetText
	);

	// screen_names
	$tweetText = preg_replace_callback(
		'/@([a-z0-9_]+)/i',
		fn(array $m):string => sprintf('<a href="https://twitter.com/%s" target="_blank">%s</a>', $m[1], $m[0]),
		$tweetText
	);

	return $tweetText;
}
