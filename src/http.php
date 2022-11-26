<?php
/**
 * http.php
 *
 * @created      25.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

use chillerlan\DotEnv\DotEnv;
use chillerlan\HTTP\HTTPOptions;
use chillerlan\HTTP\Psr18\CurlClient;
use chillerlan\HTTP\Psr7\Request;
use chillerlan\HTTP\Utils\QueryUtil;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * @var \Psr\Log\LoggerInterface $logger
 */
require_once __DIR__.'/logger.php';

// alternatively, you can use the pre-generated bearer token from  https://developer.twitter.com/en/portal/projects-and-apps
// get the token from the environment/config
if(isset($_SERVER['GITHUB_ACTIONS'])){
	$token = getenv('TWITTER_BEARER');
}
else{
	// a dotenv instance for the config
	$env   = (new DotEnv(__DIR__.'/../config', '.env', false))->load();
	$token = $env->get('TWITTER_BEARER');
}

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
