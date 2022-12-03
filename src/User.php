<?php
/**
 * Class User
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use JsonSerializable;
use stdClass;
use function get_object_vars;
use function json_decode;
use function json_encode;
use function preg_replace;
use function property_exists;
use function str_replace;
use function strtotime;
use const JSON_THROW_ON_ERROR;

/**
 *
 */
class User implements JsonSerializable{

	public readonly int $id;
	public readonly string $screen_name;
	public readonly string $name;
	public readonly string $description;
	public readonly string $location;
	public readonly string $url;
	public readonly int $followers_count;
	public readonly int $friends_count;
	public readonly int $statuses_count;
	public readonly int $favourites_count;
	public readonly int $created_at;
	public readonly bool $protected;
	public readonly bool $verified;
	public readonly bool $muting;
	public readonly bool $blocking;
	public readonly bool $blocked_by;
	public readonly bool $is_cryptobro;
	public readonly bool $clown_emoji;
	public readonly string $profile_image;
	public readonly string $profile_image_s;
	public readonly string $profile_banner;

	/**
	 *
	 */
	public function __construct(array|stdClass $user, bool $fromAPI = false){
		$user = json_decode(json: json_encode(value: $user, flags: JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

		$fromAPI
			? $this->parseUser($user)
			: $this->setVars($user);
	}

	/**
	 *
	 */
	protected function setVars(array|stdClass $vars):void{
		foreach($vars as $property => $value){
			if(property_exists($this, $property)){
				$this->{$property} = $value;
			}
		}
	}

	/**
	 *
	 */
	protected function parseUser(stdClass $user):void{

		foreach(['name', 'description', 'location', 'url'] as $var){
			${$var} = preg_replace('/\s+/', ' ', $user->{$var} ?? '');
		}

		foreach($user->entities->description->urls ?? [] as $entity){
			$description = str_replace($entity->url, $entity->expanded_url ?? $entity->url ?? '', $description);
		}

		foreach($user->entities->url->urls ?? [] as $entity){
			$url = str_replace($entity->url, $entity->expanded_url ?? $entity->url ?? '', $url);
		}

		$ptofile_image_s = $user->profile_image_url_https ?? $user->profile_image_url ?? '';

		$this->id               = $user->id;
		$this->screen_name      = $user->screen_name ?? $user->username;
		$this->name             = $name;
		$this->description      = $description;
		$this->location         = $location;
		$this->url              = $url;
		$this->followers_count  = $user->followers_count ?? $user->public_metrics->followers_count ?? 0;
		$this->friends_count    = $user->friends_count ?? $user->public_metrics->following_count ?? 0;
		$this->statuses_count   = $user->statuses_count ?? $user->public_metrics->tweet_count ?? 0;
		$this->favourites_count = $user->favourites_count ?? 0;
		$this->created_at       = strtotime($user->created_at);
		$this->protected        = (bool)($user->protected ?? false);
		$this->verified         = (bool)($user->verified ?? false);
		$this->muting           = (bool)($user->muting ?? false);
		$this->blocking         = (bool)($user->blocking ?? false);
		$this->blocked_by       = (bool)($user->blocked_by ?? false);
		$this->is_cryptobro     = $user->ext_has_nft_avatar ?? false;
		$this->clown_emoji      = $user->ext_is_blue_verified ?? false;
		$this->profile_image_s  = $ptofile_image_s;
		$this->profile_image    = str_replace('_normal.', '.', $ptofile_image_s);
		$this->profile_banner   = $user->profile_banner_url ?? '';
	}

	/**
	 *
	 */
	public function jsonSerialize():mixed{
		return get_object_vars($this);
	}

}
