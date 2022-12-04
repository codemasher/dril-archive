<?php
/**
 * Class Timeline
 *
 * @created      29.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use ArrayAccess;
use Countable;
use Exception;
use JsonSerializable;
use RuntimeException;
use function array_chunk;
use function array_column;
use function array_combine;
use function array_key_last;
use function array_map;
use function array_multisort;
use function array_values;
use function ceil;
use function count;
use function file_exists;
use function file_put_contents;
use function implode;
use function in_array;
use function mkdir;
use function realpath;
use function sprintf;
use const SORT_ASC;
use const SORT_DESC;
use const SORT_NUMERIC;

/**
 *
 */
class Timeline implements ArrayAccess, Countable, JsonSerializable{

	/** @var \codemasher\DrilArchive\Tweet[] */
	protected array $tweets = [];
	/** @var \codemasher\DrilArchive\User[] */
	protected array $users  = [];

	/**
	 * sets/replaces a User object
	 */
	public function setUser(User $user):self{
		$this->users[$user->id] = $user;

		return $this;
	}

	public function sortby(string $sortkey, int $order = SORT_ASC):self{

		$keys = ['id', 'user_id', 'created_at', 'retweet_count', 'like_count', 'reply_count', 'quote_count', 'conversation_id'];

		if(!in_array($sortkey, $keys)){
			throw new RuntimeException(sprintf('invalid sort key: %s', $sortkey));
		}

		// sort the array
		$dir = $order === SORT_DESC ? SORT_DESC : SORT_ASC;
		array_multisort($this->column($sortkey), $dir, SORT_NUMERIC, $this->tweets);

		// retain the tweet id keys
		$this->tweets = array_combine($this->column('id'), array_values($this->tweets));

		return $this;
	}

	/**
	 * @see http://api.prototypejs.org/language/Array/prototype/reverse/
	 */
	public function reverse():self{
		$this->tweets = array_reverse($this->tweets);

		return $this;
	}

	/**
	 * @see http://api.prototypejs.org/language/Array/prototype/first/
	 */
	public function first():?Tweet{
		return $this->tweets[array_key_first($this->tweets)] ?? null;
	}

	/**
	 * @see http://api.prototypejs.org/language/Array/prototype/last/
	 */
	public function last():?Tweet{
		return $this->tweets[array_key_last($this->tweets)] ?? null;
	}

	/**
	 *
	 */
	public function column(string $column, string $index_key = null):array{
		return array_column($this->tweets, $column, $index_key);
	}

	/**
	 * @see \ArrayAccess
	 * @inheritDoc
	 */
	public function offsetExists(mixed $offset):bool{
		return array_key_exists((int)$offset, $this->tweets);
	}

	/**
	 * @see \ArrayAccess
	 * @inheritDoc
	 */
	public function offsetGet(mixed $offset):?Tweet{
		return $this->tweets[(int)$offset] ?? null;
	}

	/**
	 * @see \ArrayAccess
	 * @inheritDoc
	 */
	public function offsetSet(mixed $offset, mixed $value):void{
		$tweet = $value instanceof Tweet ? $value : new Tweet($value);

		$this->tweets[$tweet->id] = $tweet;
	}

	/**
	 * @see \ArrayAccess
	 * @inheritDoc
	 */
	public function offsetUnset(mixed $offset):void{
		unset($this->tweets[(int)$offset]);
	}

	/**
	 * @see \Countable
	 * @inheritDoc
	 */
	public function count():int{
		return count($this->tweets);
	}

	public function countUsers():int{
		return count($this->users);
	}

	/**
	 * @see \JsonSerializable
	 * @inheritDoc
	 */
	public function jsonSerialize():array{
		return [
			'tweets' => array_values($this->tweets),
			'users'  => array_values($this->users),
		];
	}

	/**
	 *
	 */
	protected function setTweetUsers(Tweet $tweet):Tweet{

		if(isset($this->users[$tweet->user_id])){
			$tweet->setUser($this->users[$tweet->user_id]);
		}

		if($tweet->in_reply_to_user_id !== null && isset($this->users[$tweet->in_reply_to_user_id])){
			$tweet->setInReplyToUser($this->users[$tweet->in_reply_to_user_id]);
		}

		if(isset($tweet->quoted_status)){
			$this->setTweetUsers($tweet->quoted_status);
		}

		if(isset($tweet->retweeted_status)){
			$this->setTweetUsers($tweet->retweeted_status);
		}

		return $tweet;
	}

	/**
	 * @throws \Exception
	 */
	public function toHTML(string $outpath, int $tweetsPerPage = null, int $maxPages = null):void{

		if(empty($outpath)){
			throw new Exception('invalid html outpath');
		}

		if(!file_exists($outpath)){
			mkdir(directory: $outpath, recursive: true);
		}

		$outpath      = realpath($outpath);
		$tlcount      = $this->count();
		$headerHeight = 96;

		if($tweetsPerPage === null || $tweetsPerPage < 0 || $tweetsPerPage > $tlcount){
			$tweetsPerPage = $tlcount;
			$headerHeight  = 48;
		}

		$pages = (int)ceil($tlcount / $tweetsPerPage);
		$page  = 0;

		// create avatar CSS
		$avatarCSS = implode('', array_map(
			fn(User $user):string => sprintf(".avatar-%s{content:url(\"%s\")}\n", $user->screen_name, $user->profile_image_s),
			$this->users
		));

		file_put_contents($outpath.'/avatars.css', $avatarCSS);

		foreach(array_chunk($this->tweets, $tweetsPerPage) as $chunk){

			// create pagination
			$pagination = '';

			if($tweetsPerPage !== null && $pages > 0 && ($maxPages === null || $maxPages > 1)){
				$pagination = '<div id="pagination-wrapper">';

				for($i = 0; $i < $pages; $i++){
					$pagination .= sprintf(
						'<a%s href="./%s.html"><span>%s</span></a>',
						$i === $page ? ' class="currentpage"' : '',
						$i === 0 ? 'index' : 'page-'.($i + 1),
						$i + 1
					);
				}

				$pagination .= '</div>';
			}

			// render tweets
			$timelineHTML = '';

			/** @var \codemasher\DrilArchive\Tweet $tweet */
			foreach($chunk as $tweet){
				$timelineHTML .= $this->setTweetUsers($tweet)->toHTML();
			}

			$html = '<!DOCTYPE html>
<!-- suppress ALL -->
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
<div id="header-wrapper">
	<div>
		<a href="./"><img id="header-image" src="./assets/dril.jpg" alt="Dril Archive" /></a>
		<a href="./dril-top-liked.html">top liked</a> &bull;
		<a href="./dril-top-retweeted.html">top retweeted</a> &bull;
		<a href="https://twitter.com/dril" target="_blank">@dril</a> &bull;
		<a href="https://github.com/codemasher/dril-archive#downloads" target="_blank">Download</a> &bull;
		<a href="https://github.com/codemasher/dril-archive" target="_blank">GitHub</a>
	</div>
</div>'.$pagination.'
<div id="timeline-wrapper" style="height: calc(100% - '.$headerHeight.'px);">'.$timelineHTML.'
</div>
<!-- https://github.com/twitter/twemoji -->
<script src="https://twemoji.maxcdn.com/v/latest/twemoji.min.js" crossorigin="anonymous"></script>
<script>twemoji.parse(document.getElementById(\'timeline-wrapper\'))</script>
</body>
</html>';

			// save to file
			$filename = sprintf('%s/%s.html', $outpath, ($page === 0 ? 'index' : 'page-'.($page + 1)));
			file_put_contents($filename, $html);
			$page++;

			if($maxPages !== null && $page >= $maxPages){
				break;
			}

		}

	}

}
