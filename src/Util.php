<?php
/**
 * Class Util
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

namespace codemasher\DrilArchive;

use InvalidArgumentException;
use RuntimeException;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function htmlentities;
use function in_array;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function realpath;
use function sprintf;
use function strtolower;
use function trim;
use function utf8_encode;
use const ENT_NOQUOTES;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 *
 */
class Util{

	/**
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public static function mkdir(string $dir):string{

		if(empty($dir) || in_array($dir, ['/', '.', '..'])){
			throw new InvalidArgumentException('invalid directory');
		}

		if(!file_exists($dir) && !mkdir(directory: $dir, recursive: true)){
			throw new RuntimeException(sprintf('could not create directory: %s', $dir));
		}

		$dir = realpath($dir);

		if(!$dir){
			throw new InvalidArgumentException('invalid directory (realpath)');
		}

		return $dir;
	}

	/**
	 * load a JSON string from file into an array or object
	 */
	public static function loadJSON(string $filepath, bool $associative = false):mixed{
		return json_decode(json: file_get_contents($filepath), associative: $associative, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * save an array or object to a JSON file
	 */
	public static function saveJSON(string $filepath, array|object $data):void{
		$jsonFlags = JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT;

		file_put_contents($filepath, json_encode($data, $jsonFlags));
	}

	/**
	 * create <a> links for URLs, hastags and screen names
	 */
	public static function parseLinks(string $tweetText):string{

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

	/**
	 * Clean special characters out of strings to use them as URL-part or directory name
	 *
	 * @link http://de2.php.net/manual/de/function.preg-replace.php#90485
	 * @link http://unicode.e-workers.de
	 */
	public static function string2url(string $str, bool $lowercase = true):string{

		$table = [
			//add more characters if needed
			'Ä'=>'Ae',
			'ä'=>'ae',
			'Ö'=>'Oe',
			'ö'=>'oe',
			'Ü'=>'Ue',
			'ü'=>'ue',
			'ß'=>'ss',
			'@'=>'-at-',
			'.'=>'-',
			'_'=>'-'
		];

		//replace custom unicode characters
		$str      = strtr(trim($str), $table);
		//replace (nearly) all chars which have htmlentities
		$entities = htmlentities(utf8_encode($str), ENT_NOQUOTES, 'UTF-8');
		$str      = preg_replace('#&([a-z]{1,2})(acute|grave|cedil|circ|uml|lig|tilde|ring|slash);#i', '$1', $entities);
		//clean out the rest
		$str      = preg_replace(['([\40])', '([^a-zA-Z0-9-])', '(-{2,})'], '-', $str);

		return trim($lowercase ? strtolower($str) : $str, '-');
	}


}
