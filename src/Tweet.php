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
use function date;
use function get_object_vars;
use function in_array;
use function json_decode;
use function json_encode;
use function number_format;
use function property_exists;
use function sprintf;
use function str_replace;
use function strtotime;
use function var_dump;
use const JSON_THROW_ON_ERROR;

/**
 *
 */
class Tweet implements JsonSerializable{

	public readonly int $id;
	public readonly int $user_id;
	public readonly ?User $user;
	public readonly int $created_at;
	public readonly string $text;
	public readonly ?string $source;
	public readonly int $retweet_count;
	public readonly int $favorite_count;
	public readonly int $reply_count;
	public readonly int $quote_count;
	public readonly bool $favorited;
	public readonly bool $retweeted;
	public readonly bool $possibly_sensitive;
	public readonly ?int $in_reply_to_status_id;
	public readonly ?int $in_reply_to_user_id;
	public readonly ?User $in_reply_to_user;
	public readonly ?string $in_reply_to_screen_name;
	public readonly bool $is_quote_status;
	public readonly ?int $quoted_status_id;
	public readonly ?Tweet $quoted_status;
	public readonly ?int $retweeted_status_id;
	public readonly ?Tweet $retweeted_status;
	public readonly ?int $self_thread_id;
	public readonly ?int $conversation_id;
	public readonly array $media;
	public readonly ?array $coordinates;
	public readonly ?array $geo;
	public readonly ?array $place;

	/**
	 * @throws \JsonException
	 */
	public function __construct(array|stdClass $tweet, bool $fromAPI = false){
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
					$this->{$property} = !empty($value) ? new Tweet($value) : null;
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
#		$this->user                    = null;
		$this->created_at              = strtotime($tweet->created_at);
		$this->text                    = $text;
		$this->source                  = $tweet->source ?? null;
		$this->retweet_count           = (int)($tweet->retweet_count ?? $tweet->public_metrics->retweet_count ?? 0);
		$this->favorite_count          = (int)($tweet->favorite_count ?? $tweet->public_metrics->like_count ?? 0);
		$this->reply_count             = (int)($tweet->reply_count ?? $tweet->public_metrics->reply_count ?? 0);
		$this->quote_count             = (int)($tweet->quote_count ?? $tweet->public_metrics->quote_count ?? 0);
		$this->favorited               = $tweet->favorited ?? false;
		$this->retweeted               = $tweet->retweeted ?? false;
		$this->possibly_sensitive      = $tweet->possibly_sensitive ?? false;
		$this->in_reply_to_status_id   = $tweet->in_reply_to_status_id ?? null;
		$this->in_reply_to_user_id     = $tweet->in_reply_to_user_id ?? null;
		$this->in_reply_to_screen_name = $tweet->in_reply_to_screen_name ?? null;
		$this->is_quote_status         = $tweet->is_quote_status ?? false;
#		$this->quoted_status           = null;
#		$this->retweeted_status_id     = $tweet->retweeted_status_id ?? null;
#		$this->retweeted_status        = null;
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
	 *
	 */
	public function setQuotedStatus(Tweet $tweet):self{
		$this->quoted_status = $tweet;

		return $this;
	}

	/**
	 *
	 */
	public function setQuotedStatusID(int $id):self{
		$this->quoted_status_id = $id;

		return $this;
	}

	/**
	 *
	 */
	public function setRetweetedStatus(Tweet $tweet):self{
		$this->retweeted_status = $tweet;

		return $this;
	}

	/**
	 *
	 */
	public function setRetweetedStatusID(int $id):self{
		$this->retweeted_status_id = $id;

		return $this;
	}

	/**
	 * @see https://developer.twitter.com/en/docs/twitter-api/data-dictionary/object-model/media
	 */
	public static function parseMedia(object $media):array{
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
	 *
	 */
	public function jsonSerialize():mixed{
		return get_object_vars($this);
	}

	public function toHTML(array $users):string{



		return $this->renderTweet($this, $users);
	}

	protected function renderTweet(Tweet $tweet, array $users, bool $qt = false):string{
		$t      = $tweet;
		$status = '';
		$quoted = '';
		$media  = '';

		if(isset($tweet->retweeted_status) && !empty($tweet->retweeted_status)){
			$t      = $tweet->retweeted_status;
			$status = sprintf(
				'<a href="https://twitter.com/%1$s/status/%2$s" target="_blank"><div class="retweet"></div>@%1$s retweeted</a>',
				$users[$tweet->user_id]->screen_name,
				$tweet->id
			);
		}
		elseif($tweet->in_reply_to_status_id !== null){
			$status = sprintf(
				'<a href="https://twitter.com/%1$s/status/%2$s" target="_blank"><div class="reply"></div>In reply to @%1$s</a>',
				$users[$tweet->in_reply_to_status_id]->screen_name ?? $tweet->in_reply_to_screen_name,
				$tweet->in_reply_to_status_id
			);
		}

		if(!empty($status)){
			$status = sprintf('<div class="status">%s</div>', $status);
		}

		if(!$qt && isset($tweet->quoted_status) && !empty($this->quoted_status)){
			$quoted = $this->renderTweet($tweet->quoted_status, $users, true);
		}

		// just ignoring missing users here, just give a warning
#		if(!isset($users[$t['user_id']])){
#			$logger->warning(sprintf('user not found: %s', $t['user_id']));
#		}

		$user         = $users[$t->user_id] ?? '';
		$screen_name  = $user->screen_name ?? '';
		$display_name = $user->name ?? '';

		$profile      = sprintf('https://twitter.com/%s', $screen_name);
		$statuslink   = sprintf('https://twitter.com/%s/status/%s', $display_name, $t->id);
		$datetime     = date('c', $t->created_at);
		$dateDisplay  = date('M d, Y', $t->created_at);
		$text         = Util::parseLinks($t->text);

		if(!empty($t->media)){
			$media .= '<div class="images">';

			foreach($t->media as $m){
				$m = (array)$m;
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
			<div><div class="reply"></div>'.number_format($t->reply_count, 0, '', '.').'</div>
			<div><div class="retweet"></div>'.number_format($t->retweet_count, 0, '', '.').'</div>
			<div><div class="like"></div>'.number_format($t->favorite_count, 0, '', '.').'</div>
		</div>
	</div>
</article>';


	}
}
