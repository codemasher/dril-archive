<?php
/**
 * Class DrilArchiveOptions
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use chillerlan\OAuth\OAuthOptions;
use function str_replace;

/**
 *
 */
class DrilArchiveOptions extends OAuthOptions{

	protected string $apiToken = '';
	protected string $adaptiveRequestToken = '';
	protected string $adaptiveCsrfToken = '';
	protected string $adaptiveCookie = '';
	protected bool $fetchFromAdaptiveSearch = false;
	protected bool $fetchFromAPISearch = false;
	protected string $builddir;
	protected string $outdir;
	protected bool $fromCachedApiResponses = true;
	protected string $query = '';
	protected int $retriesOn429 = 5;
	protected ?string $drilCSV = null;
	protected string $filename = 'dril';
	protected bool $fetchV2RTs = false;

	/**
	 *
	 */
	protected function set_adaptiveRequestToken(string $adaptiveRequestToken):void{
		$this->adaptiveRequestToken = str_replace('Bearer ', '', $adaptiveRequestToken);
	}

	/**
	 *
	 */
	protected function set_builddir(string $builddir):void{
		$this->builddir = Util::mkdir($builddir);
	}

	/**
	 *
	 */
	protected function set_outdir(string $outdir):void{
		$this->outdir = Util::mkdir($outdir);
	}

}
