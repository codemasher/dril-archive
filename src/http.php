<?php
/**
 * http.php
 *
 * @created      25.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

use chillerlan\DotEnv\DotEnv;
use chillerlan\HTTP\HTTPOptions;
use chillerlan\HTTP\Psr18\CurlClient;
use chillerlan\HTTP\Psr7\Request;
use chillerlan\HTTP\Utils\QueryUtil;
use Psr\Http\Message\ResponseInterface;
use function getenv;
use function realpath;
use function sprintf;
use function str_replace;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * @var \Psr\Log\LoggerInterface $logger
 */
require_once __DIR__.'/logger.php';

// options for the http/oauth clients
$httpOptions = new HTTPOptions([
	'ca_info'    => realpath(__DIR__.'/../config/cacert.pem'), // https://curl.haxx.se/ca/cacert.pem
	'user_agent' => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)',
]);

$http = new CurlClient(options: $httpOptions, logger: $logger); // PSR-18

/**
 * prepare and fire a http request through PSR-7/PSR-18
 */
function httpRequest(string $url, array $params):ResponseInterface{
	global $token, $http;

	$request = (new Request('GET', QueryUtil::merge($url, $params)))
		->withHeader('Authorization', sprintf('Bearer %s', $token));

	return $http->sendRequest($request);
}

/**
 * Prepare a bearer token; if a $token is given it is assumed that it's the request token for the adaptive search API.
 * Otherwise it attempts to get a token from the environment.
 */
function getToken(string $token = null):string{

	if($token !== null){
		return str_replace('Bearer ', '', $token);
	}

	// get the token from the environment/config
	if(isset($_SERVER['GITHUB_ACTIONS'])){
		return getenv('TWITTER_BEARER');
	}

	// a dotenv instance for the config
	$env = (new DotEnv(__DIR__.'/../config', '.env', false))->load();

	return $env->get('TWITTER_BEARER');
}
