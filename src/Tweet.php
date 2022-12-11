<?php
/**
 * Class Tweet
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use JsonSerializable;
use stdClass;
use function count;
use function date;
use function get_object_vars;
use function in_array;
use function json_decode;
use function json_encode;
use function nl2br;
use function number_format;
use function property_exists;
use function sprintf;
use function str_replace;
use function strtotime;
use const JSON_THROW_ON_ERROR;

/**
 *
 */
class Tweet implements JsonSerializable{

	public int $id;
	public int $user_id;
	public int $created_at;
	public string $text;
	public ?string $source;
	public int $retweet_count;
	public int $like_count;
	public int $reply_count;
	public int $quote_count;
	public bool $favorited;
	public bool $retweeted;
	public bool $possibly_sensitive;
	public ?int $in_reply_to_status_id;
	public ?int $in_reply_to_user_id;
	public ?string $in_reply_to_screen_name;
	public bool $is_quote_status;
	public ?int $quoted_status_id;
	public ?int $retweeted_status_id;
	public ?int $self_thread_id;
	public ?int $conversation_id;
	public array $media;
	public ?array $coordinates;
	public ?array $geo;
	public ?array $place;

	public ?Tweet $quoted_status;
	public ?Tweet $retweeted_status;

	public ?User $user;
	public ?User $in_reply_to_user;

	/**
	 * @throws \JsonException
	 */
	public function __construct(Tweet|stdClass|array $tweet, bool $fromAPI = false){
		$tweet = json_decode(json: json_encode(value: $tweet, flags: JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

		$fromAPI
			? $this->parseTweet($tweet)
			: $this->setVars($tweet);
	}

	/**
	 *
	 */
	protected function setVars(array|stdClass $vars):void{
		foreach($vars as $property => $value){
			if(property_exists($this, $property)){

				if(in_array($property, ['quoted_status', 'retweeted_status'])){
					$this->{$property} = !empty($value) ? new self($value) : null;
				}
				elseif(in_array($property, ['coordinates', 'geo', 'place'])){
					$this->{$property} = !empty($value) ? (array)$value : null;
				}
				else{
					$this->{$property} = $value;
				}

			}
		}
	}

	/**
	 *
	 */
	protected function parseTweet(stdClass $tweet):void{
		$text       = $tweet->full_text ?? $tweet->text ?? '';
		$mediaItems = [];

		foreach($tweet->entities->urls ?? [] as $entity){
			$text = str_replace($entity->url, $entity->expanded_url ?? $entity->url ?? '', $text);
		}

		foreach($tweet->entities->media ?? [] as $media){
			// we'll just remove the shortened media URL as it is of no use
			$text         = str_replace($media->url, '', $text);
			$mediaItems[] = $this::parseMedia($media);
		}

		$this->id                      = (int)$tweet->id;
		$this->user_id                 = (int)($tweet->user_id ?? $tweet->author_id ?? $tweet->user->id ?? 0);
		$this->created_at              = strtotime($tweet->created_at);
		$this->text                    = $text;
		$this->source                  = $tweet->source ?? null;
		$this->retweet_count           = (int)($tweet->retweet_count ?? $tweet->public_metrics->retweet_count ?? 0);
		$this->like_count              = (int)($tweet->favorite_count ?? $tweet->public_metrics->like_count ?? 0);
		$this->reply_count             = (int)($tweet->reply_count ?? $tweet->public_metrics->reply_count ?? 0);
		$this->quote_count             = (int)($tweet->quote_count ?? $tweet->public_metrics->quote_count ?? 0);
		$this->favorited               = $tweet->favorited ?? false;
		$this->retweeted               = $tweet->retweeted ?? false;
		$this->possibly_sensitive      = $tweet->possibly_sensitive ?? false;
		$this->in_reply_to_status_id   = $tweet->in_reply_to_status_id ?? null;
		$this->in_reply_to_user_id     = $tweet->in_reply_to_user_id ?? null;
		$this->in_reply_to_screen_name = $tweet->in_reply_to_screen_name ?? null;
		$this->is_quote_status         = $tweet->is_quote_status ?? false;
		$this->self_thread_id          = $tweet->self_thread->id ?? null;
		$this->conversation_id         = $tweet->conversation_id ?? null;
		$this->media                   = $mediaItems;
		$this->place                   = !empty($tweet->place) ? (array)$tweet->place : null;
		$this->coordinates             = !empty($tweet->coordinates) ? (array)$tweet->coordinates : null;
		$this->geo                     = !empty($tweet->geo) ? (array)$tweet->geo : null;

		// initialize only if it exists to leave the option for the setter
		if(isset($tweet->quoted_status_id) && !empty($tweet->quoted_status_id)){
			$this->quoted_status_id = $tweet->quoted_status_id;
		}

	}

	/**
	 * @see https://developer.twitter.com/en/docs/twitter-api/data-dictionary/object-model/media
	 */
	public static function parseMedia(object $media):stdClass{
		$m = new stdClass;

		$m->id                 = $media->id;
		$m->media_key          = $media->media_key ?? null;
		$m->source_user_id     = $media->source_user_id ?? null;
		$m->type               = $media->type;
		$m->url                = $media->media_url_https ?? $media->media_url;
		$m->alt_text           = $media->ext_alt_text ?? '';
		$m->possibly_sensitive = $tweet->ext_sensitive_media_warning ?? null;
		$m->width              = $media->original_info->width ?? null;
		$m->height             = $media->original_info->height ?? null;
		$m->variants           = $media->video_info->variants ?? null;
		$m->aspect_ratio       = null;

		if($m->width > 0 && $m->height > 0){
			$m->aspect_ratio = round($m->width / $m->height, 5);
		}

		return $m;
	}

	/**
	 *
	 */
	public function jsonSerialize():array{
		return get_object_vars($this);
	}

	/**
	 *
	 */
	public function toHTML():string{
		return $this->renderTweet($this);
	}

	/**
	 *
	 */
	protected function renderTweet(Tweet $tweet, bool $qt = false):string{
		$t      = $tweet;
		$status = '';
		$quoted = '';
		$media  = '';

		if(isset($tweet->retweeted_status) && !empty($tweet->retweeted_status)){
			$t      = $tweet->retweeted_status;
			$status = sprintf(
				'<a href="https://twitter.com/%s/status/%s" target="_blank"><div class="retweet"></div>%s retweeted</a>',
				$tweet->user->screen_name,
				$tweet->id,
				$tweet->user->name
			);
		}
		elseif($tweet->in_reply_to_status_id !== null){
			$status = sprintf(
				'<a href="https://twitter.com/%s/status/%s" target="_blank"><div class="reply"></div>In reply to %s</a>',
				$tweet->in_reply_to_user->screen_name ?? $tweet->in_reply_to_screen_name,
				$tweet->in_reply_to_status_id,
				$tweet->in_reply_to_user->name ?? '@'.$tweet->in_reply_to_screen_name
			);
		}

		// recursion
		if(!$qt && isset($tweet->quoted_status) && !empty($this->quoted_status)){
			$quoted = $this->renderTweet($tweet->quoted_status, true);
		}

		if(!empty($status)){
			$status = sprintf('<div class="status">%s</div>', $status);
		}

		$screen_name  = $t->user->screen_name ?? '';
		$display_name = $t->user->name ?? '';

		$profilelink  = sprintf('https://twitter.com/%s', $screen_name);
		$statuslink   = sprintf('https://twitter.com/%s/status/%s', $screen_name, $t->id);
		$datetime     = date('c', $t->created_at);
		$dateDisplay  = date('M d, Y', $t->created_at);
		$text         = Util::parseLinks(nl2br($t->text));
		$mediacount   = count($t->media);

		if(!empty($t->media)){
			$media .= sprintf('<div class="images grid-%s">', $mediacount);

			foreach($t->media as $m){
				if($m->type === 'photo'){
					$media .= sprintf(
						'<div style="aspect-ratio: %s;"><img alt="%s" src="%s" style="%s"/></div>',
						$m->aspect_ratio,
						$m->alt_text,
						$m->url,
						$m->width < $m->height ? 'width: 100%; height: auto;' : 'width: auto; height: 100%;'
					);
				}
			}

			$media .= '</div>';
		}


		return '
<article class="tweet">'.$status.'
	<div class="avatar"><img class="avatar-'.$screen_name.'" alt="'.$screen_name.' avatar" /></div>
	<div class="body">
		<div class="header">
			<a href="'.$profilelink.'" target="_blank"><span class="user">'.$display_name.'</span></a>
			<a href="'.$profilelink.'" target="_blank"><span class="screenname">@'.$screen_name.'</span></a>
			<span>·</span>
			<a href="'.$statuslink.'" target="_blank"><time class="timestamp" datetime="'.$datetime.'">'.$dateDisplay.'</time></a>
		</div>
		<div dir="auto" class="text">'.$text.'</div>
		<div class="media">'.$media.$quoted.'</div>
		<div class="footer">
			<div><div class="reply"></div>'.number_format($t->reply_count, 0, '', '.').'</div>
			<div><div class="retweet"></div>'.number_format($t->retweet_count, 0, '', '.').'</div>
			<div><div class="like"></div>'.number_format($t->like_count, 0, '', '.').'</div>
		</div>
	</div>
</article>';


	}
}
