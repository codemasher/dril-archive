<?php
/**
 * compile-html.php
 *
 * @created      25.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use function array_chunk;
use function array_map;
use function count;
use function date;
use function file_exists;
use function file_put_contents;
use function implode;
use function mkdir;
use function number_format;
use function realpath;
use function rename;
use function sprintf;

/**
 * @var \Psr\Log\LoggerInterface $logger
 */
require_once __DIR__.'/logger.php';
require_once __DIR__.'/functions.php';

$timeline   = __DIR__.'/../output/dril-timeline.json';
$tweetUsers = __DIR__.'/../output/dril-users.json';
$htmlOut    = __DIR__.'/../output';

// create a single html file that contains all tweets
renderPages($timeline, $tweetUsers, $htmlOut, null);
// rename
rename($htmlOut.'/index.html', $htmlOut.'/dril.html');
// create a paginated version
renderPages($timeline, $tweetUsers, $htmlOut, 1000);

/**
 *
 */
function renderPages(string $timelineJSON, string $userJSON, string $outpath, int $maxTweets = null):void{
	global $logger;

	$timeline   = loadJSON($timelineJSON, true);
	$tweetUsers = loadJSON($userJSON, true);

	if(!file_exists($outpath)){
		mkdir(directory: $outpath, recursive: true);
	}

	$outpath = realpath($outpath);
	$maxTweets ??= count($timeline);
	$page = 0;

	// create avatar CSS
	$avatarCSS = implode('', array_map(
		fn(array $user):string => sprintf(".avatar-%s{content:url(\"%s\")}\n", $user['screen_name'], $user['profile_image_s']),
		$tweetUsers
	));

	file_put_contents($outpath.'/avatars.css', $avatarCSS);

	foreach(array_chunk($timeline, $maxTweets) as $chunk){
		$timelineHTML = '';

		foreach($chunk as $tweet){
			$timelineHTML .= renderTweet($tweet, $tweetUsers);
		}

		$html = '<!DOCTYPE html>
<!--suppress ALL -->
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
	<title>dril archive</title>
	<link rel="icon" type="image/png" sizes="48x48" href="./assets/favicon.ico">
	<link rel="stylesheet" href="./assets/timeline.css">
	<link rel="stylesheet" href="./avatars.css">
</head>
<body>
<div id="timeline-container">'.$timelineHTML.'
</div>
<!-- https://github.com/twitter/twemoji -->
<script src="https://twemoji.maxcdn.com/v/latest/twemoji.min.js" crossorigin="anonymous"></script>
<script>twemoji.parse(document.getElementById(\'timeline-container\'))</script>
</body>
</html>';

		$filename = sprintf('%s/%s.html', $outpath, ($page === 0 ? 'index' : 'page-'.$page));

		file_put_contents($filename, $html);

		$logger->info(sprintf('created html page %s: %s', $page, $filename));

		$page++;
	}

}

/**
 *
 */
function renderTweet(array $tweet, array $users, bool $qt = false):string{#
	global $logger;

	$t       = $tweet;
	$status = '';
	$quoted  = '';
	$media   = '';

	if($tweet['retweeted_status'] !== null){
		$t      = $tweet['retweeted_status'];
		$status = sprintf(
			'<a href="https://twitter.com/%1$s/status/%2$s" target="_blank"><div class="retweet"></div>@%1$s retweeted</a>',
			$users[$tweet['user_id']]['screen_name'],
			$tweet['id']
		);
	}
	elseif($tweet['in_reply_to_status_id'] !== null){
		$status = sprintf(
			'<a href="https://twitter.com/%1$s/status/%2$s" target="_blank"><div class="reply"></div>In reply to @%1$s</a>',
			$users[$tweet['in_reply_to_user_id']]['screen_name'] ?? $tweet['in_reply_to_screen_name'],
			$tweet['in_reply_to_status_id']
		);
	}

	if(!empty($status)){
		$status = sprintf('<div class="status">%s</div>', $status);
	}

	if(!$qt && $tweet['quoted_status'] !== null){
		$quoted = renderTweet($tweet['quoted_status'], $users, true);
	}

	// just ignoring missing users here, just give a warning
	if(!isset($users[$t['user_id']])){
		$logger->warning(sprintf('user not found: %s', $t['user_id']));
	}

	$user         = $users[$t['user_id']] ?? '';
	$screen_name  = $user['screen_name'] ?? '';
	$display_name = $user['name'] ?? '';

	$profile      = sprintf('https://twitter.com/%s', $screen_name);
	$statuslink   = sprintf('https://twitter.com/%s/status/%s', $screen_name, $t['id']);
	$datetime     = date('c', $t['created_at']);
	$dateDisplay  = date('M d, Y', $t['created_at']);
	$text         = parseLinks($t['text']);

	if(!empty($t['media'])){
		$media .= '<div class="images">';

		foreach($t['media'] as $m){
			if($m['type'] === 'photo'){
				$media .= sprintf('<div><img alt="%s" src="%s" /></div>', $m['alt_text'], $m['url']);
			}
		}

		$media .= '</div>';
	}


	return '
<article class="tweet">'.$status.'
	<div class="avatar"><img class="avatar-'.$screen_name.'" alt="'.$screen_name.' avatar" /></div>
	<div class="body">
		<div class="header">
			<a href="'.$profile.'" target="_blank"><span class="user">'.$display_name.'</span></a>
			<a href="'.$profile.'" target="_blank"><span class="screenname">@'.$screen_name.'</span></a>
			<span>·</span>
			<a href="'.$statuslink.'" target="_blank"><time class="timestamp" datetime="'.$datetime.'">'.$dateDisplay.'</time></a>
		</div>
		<div dir="auto" class="text">'.$text.'</div>
		<div class="media">'.$media.$quoted.'</div>
		<div class="footer">
			<div><div class="reply"></div>'.number_format($t['reply_count'], 0, '', '.').'</div>
			<div><div class="retweet"></div>'.number_format($t['retweet_count'], 0, '', '.').'</div>
			<div><div class="like"></div>'.number_format($t['favorite_count'], 0, '', '.').'</div>
		</div>
	</div>
</article>';

}



