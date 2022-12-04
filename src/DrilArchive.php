<?php
/**
 * Class DrilArchive
 *
 * @created      28.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */
namespace codemasher\DrilArchive;

use chillerlan\HTTP\Psr17\FactoryHelpers;
use chillerlan\HTTP\Psr18\CurlClient;
use chillerlan\HTTP\Psr7\Request;
use chillerlan\HTTP\Psr7\Response;
use chillerlan\HTTP\Utils\MessageUtil;
use chillerlan\HTTP\Utils\QueryUtil;
use chillerlan\Settings\SettingsContainerInterface;
use Exception;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;
use function array_chunk;
use function array_combine;
use function array_keys;
use function array_search;
use function array_shift;
use function array_values;
use function count;
use function fclose;
use function feof;
use function fgetcsv;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function implode;
use function intval;
use function json_decode;
use function json_encode;
use function md5;
use function mktime;
use function preg_match_all;
use function realpath;
use function rename;
use function sleep;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function time;
use function trim;
use const DIRECTORY_SEPARATOR;
use const SORT_DESC;

/**
 *
 */
class DrilArchive{

	protected SettingsContainerInterface $options;
	protected ClientInterface $http;
	protected LoggerInterface $logger;

	protected array $tempUsers     = [];
	protected array $tempTimeline  = [];
	protected string $cachedir     = '';
	protected int $since;

	public function __construct(SettingsContainerInterface $options){
		$this->options = $options;

		// a log handler for STDOUT (or STDERR if you prefer)
		$logHandler = (new StreamHandler('php://stdout', LogLevel::INFO))
			->setFormatter((new LineFormatter(null, 'Y-m-d H:i:s', true, true)));

		// invoke the worker instances
		$this->logger   = new Logger('log', [$logHandler]); // PSR-3
		$this->http     = new CurlClient(options: $this->options, logger: $this->logger); // PSR-18
	}

	/**
	 * prepare and fire a http request through PSR-7/PSR-18
	 */
	protected function httpRequest(string $path, array $params, string $cachefile, string $token = null):?ResponseInterface{
		$filename = sprintf('%s/'.$cachefile.'.json', $this->cachedir, md5(implode(',', array_values($params))));

		// try to fetch from cached data
		if($this->options->fromCachedApiResponses && file_exists($filename)){
			$data = file_get_contents($filename);

			return (new Response)->withBody(FactoryHelpers::create_stream($data));
		}

		$request = (new Request('GET', QueryUtil::merge('https://api.twitter.com'.$path, $params)))
			->withHeader('Authorization', sprintf('Bearer %s', $token ?? $this->options->apiToken))
		;

		// we're running the adaptive search, so append the guest token header
		if($token !== null){
			$request = $request->withHeader('x-guest-token', $this->options->adaptiveGuestToken);
		}

		$retriesOn429 = 0;

		while(true){
			$response = $this->http->sendRequest($request);
			$status   = $response->getStatusCode();

			// try not to hammer
			sleep(2);

			if($status === 200){
				file_put_contents($filename, MessageUtil::getContents($response));

				return $response;
			}
			elseif($status === 429 && $retriesOn429 < $this->options->retriesOn429){
				$reset = (int)$response->getHeaderLine('x-rate-limit-reset');
				$now   = time();

				$retriesOn429++;

				// header might be not set - just pause for a bit
				if($reset < $now){
					sleep(10);

					continue;
				}

				$sleep = $reset - $now + 5;
				$this->logger->notice(sprintf('HTTP/429 - going to sleep for %d seconds', $sleep));

				sleep($sleep);
			}
			else{
				$this->logger->error(MessageUtil::toString($response));

				return null;
			}

		}

	}

	/**
	 *
	 */
	protected function saveTimeline(string $savepath):self{
		$timeline = new Timeline;

		foreach($this->tempTimeline as $id => $tweet){

			if(!$tweet instanceof Tweet){
				$this->logger->warning(sprintf('not a valid tweet: %s', $id));

				continue;
			}

			$timeline[$id] = $tweet;

			unset($this->tempTimeline[$id]);
		}


		foreach($this->tempUsers as $id => $user){
			$timeline->setUser($user);

			unset($this->tempUsers[$id]);
		}

		$timeline->sortby('id', SORT_DESC);

		// save
		Util::saveJSON($savepath, $timeline);

		$this->logger->info(sprintf(
			'fetched %d tweet(s) from %d user(s) in %s',
			$timeline->count(),
			$timeline->countUsers(),
			$savepath
		));


		// create a paginated version
		$timeline->toHTML($this->options->outdir, 1000);

		// create a single html file that contains all tweets
		$timeline->toHTML($this->options->builddir);
		// rename/move
		rename($this->options->builddir.'/index.html', $this->options->outdir.'/dril.html');

		// create top* timelines
		$timeline->sortby('retweet_count', SORT_DESC);
		$timeline->toHTML($this->options->builddir, 250, 1);
		// rename
		rename($this->options->builddir.'/index.html', $this->options->outdir.'/dril-top-retweeted.html');

		$timeline->sortby('like_count', SORT_DESC);
		$timeline->toHTML($this->options->builddir, 250, 1);
		// rename
		rename($this->options->builddir.'/index.html', $this->options->outdir.'/dril-top-liked.html');


		return $this;
	}

	/**
	 *
	 */
	public function compileDrilTimeline(string $timelineJSON = null, bool $scanRTs = true, int $rtSince = null):self{
		$this->cachedir     = Util::mkdir($this->options->builddir.DIRECTORY_SEPARATOR.Util::string2url($this->options->query));
		$this->tempTimeline = [];
		$this->tempUsers    = [];
		$retweets           = [];
		$csv                = [];
		$this->since        = $rtSince ?? mktime(0, 0, 0, 1, 1, 2006);

		if($timelineJSON !== null){
			$tlJSON = Util::loadJSON($timelineJSON);

			// collect the retweet IDs from the parsed timeline
			foreach($tlJSON->tweets as $tweet){
				if($scanRTs && str_starts_with($tweet->text, 'RT @') && $tweet->created_at > $this->since){
					$retweets[]              = $tweet->id;
					$this->tempTimeline[$tweet->id] = null;
				}
				else{
					// put the already parsed tweets in the output array
					$this->tempTimeline[$tweet->id] = new Tweet($tweet);
				}
			}

			foreach($tlJSON->users as $user){
				$this->tempUsers[$user->id] = new User($user);
			}

			$this->logger->info(sprintf('parsed %d tweet(s) and %d user(s) from %s', count($tlJSON->tweets), count($tlJSON->users), realpath($timelineJSON)));
		}

		if($this->options->fetchFromAdaptiveSearch){
			$this->getTimelineFromAdaptiveSearch();
		}

		if($this->options->fetchFromAPISearch){
			$this->getTimelineFromAPISearch();
		}

		if($this->options->drilCSV !== null){

			$drilCSV = $this->parseDrilCSV($this->options->drilCSV);
			// collect RT IDs from the CSV
			foreach($drilCSV as $tweet){
				$id = $tweet['id'];

				if($tweet['is_rt']){
					$retweets[]              = $id;
					$this->tempTimeline[$id] = null;
				}
				elseif(!isset($this->tempTimeline[$id])){
					$csv[]                   = $id;
					$this->tempTimeline[$id] = null;
				}
			}

		}

		// now fetch the original retweeted tweets
		$this->fetchRetweets($retweets);
		// the remaining tweets from the CSV
		$this->fetchCsvTweets($csv);
		// improperly embedded tweets and photos
		$this->updateEmbeddedMedia();
		// fetch remining user profiles
		$this->fetchUserProfiles();

		// save output
		$this->saveTimeline(sprintf('%s/dril.json', $this->options->outdir));

		return $this;
	}

	/**
	 * incremental timeline updates
	 *
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/tweets/search/api-reference/get-search-tweets
	 */
	protected function getTimelineFromAPISearch():void{

		$params = [
			'q'                => $this->options->query,
			'count'            => 100,
			'include_entities' => 'false',
			'result_type'      => 'mixed',
		];

		$count = 0;

		while(true){

			$response = $this->httpRequest('/1.1/search/tweets.json', $params, 'data-v1-search-%s');

			if($response === null){
				break;
			}

			$json = MessageUtil::decodeJSON($response);

			if(!isset($json->statuses)){
				break;
			}

			foreach($json->statuses as $tweet){
				$this->tempUsers[$tweet->user->id] = new User($tweet->user, true);

				if(!isset($this->tempTimeline[$tweet->id])){
					$this->tempTimeline[$tweet->id] = new Tweet($tweet, true);
				}
			}

			$this->logger->info(sprintf('[%s] fetched %d tweets for "%s", last id: %s', $count, count($json->statuses), $this->options->query, $json->search_metadata->max_id));

			$count++;

			if(!isset($json->search_metadata, $json->search_metadata->next_results) || empty($json->search_metadata->next_results)){
				break;
			}

			$params = QueryUtil::parse($json->search_metadata->next_results);
		}

	}

	/**
	 * retrieves the timeline for the given query and parese the response data
	 *
	 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
	 * @see https://help.twitter.com/en/using-twitter/advanced-tweetdeck-features
	 *
	 * try:
	 *   - "@username" timeline including replies
	 *   - "@username include:nativeretweets filter:nativeretweets" for RTs (returns RTs of the past week only)
	 *   - "to:username" for @mentions and replies
	 *
	 */
	protected function getTimelineFromAdaptiveSearch():void{
		$lastCursor = '';
		$tempTweets = [];
		$hash       = md5($this->options->query);
		$count      = 0;

		while(true){
			// the query parameters from the call to https://twitter.com/i/api/2/search/adaptive.json in original order
			$params = [
				'include_profile_interstitial_type'    => '1',
				'include_blocking'                     => '1',
				'include_blocked_by'                   => '1',
				'include_followed_by'                  => '1',
				'include_want_retweets'                => '1',
				'include_mute_edge'                    => '1',
				'include_can_dm'                       => '1',
				'include_can_media_tag'                => '1',
				'include_ext_has_nft_avatar'           => '1',
				'include_ext_is_blue_verified'         => '1',
				'skip_status'                          => '1',
				'cards_platform'                       => 'Web-12',
				'include_cards'                        => '1',
				'include_ext_alt_text'                 => 'true',
				'include_ext_limited_action_results'   => 'false',
				'include_quote_count'                  => 'true',
				'include_reply_count'                  => '1',
				'tweet_mode'                           => 'extended',
				'include_ext_collab_control'           => 'true',
				'include_entities'                     => 'true',
				'include_user_entities'                => 'true',
				'include_ext_media_color'              => 'false',
				'include_ext_media_availability'       => 'true',
				'include_ext_sensitive_media_warning'  => 'true',
				'include_ext_trusted_friends_metadata' => 'true',
				'send_error_codes'                     => 'true',
				'simple_quoted_tweet'                  => 'true',
				'q'                                    => $this->options->query,
#				'social_filter'                        => 'searcher_follows', // @todo
				'tweet_search_mode'                    => 'live',
				'count'                                => '100',
				'query_source'                         => 'typed_query',
				'cursor'                               => $lastCursor,
				'pc'                                   => '1',
				'spelling_corrections'                 => '1',
				'include_ext_edit_control'             => 'true',
				'ext'                                  => 'mediaStats,highlightedLabel,hasNftAvatar,voiceInfo,enrichments,superFollowMetadata,unmentionInfo,editControl,collab_control,vibe',
			];

			// remove the cursor parameter if it's empty
			if(empty($params['cursor'])){
				unset($params['cursor']);
			}

			$response = $this->httpRequest('/2/search/adaptive.json', $params, sprintf('%s-%d', $hash, $count), $this->options->adaptiveRequestToken);

			if($response === null){
				break;
			}

			$this->logger->info(sprintf('[%d] fetched data for "%s", cursor: %s', $count, $this->options->query, $lastCursor));

			if(!$this->parseAdaptiveSearchResponse($response, $tempTweets, $lastCursor)){
				break;
			}

			$count++;

			if(empty($lastCursor)){
				break;
			}

		}

		// update timeline
		foreach($this->tempTimeline as $id => &$v){
			$tweet = $tempTweets[$id];

			// embed quoted tweets
			if(isset($tweet->quoted_status_id) && isset($tempTweets[$tweet->quoted_status_id])){
				$tweet->setQuotedStatus($tempTweets[$tweet->quoted_status_id]);
			}

			$v = $tweet;
		}


		// save the output
#		$this->saveTimeline(sprintf('%s/%s.json', $this->cachedir, $hash));
	}

	/**
	 * parse the API response and fill the data arrays (passed by reference)
	 */
	protected function parseAdaptiveSearchResponse(ResponseInterface $response, array &$tempTweets, string &$lastCursor):bool{

		try{
			$json = MessageUtil::decodeJSON($response);
		}
		catch(Throwable $e){
			return false;
		}

		if(!isset($json->globalObjects->tweets, $json->globalObjects->users, $json->timeline->instructions)){
			return false;
		}

		if(empty((array)$json->globalObjects->tweets)){
			return false;
		}

		foreach($json->globalObjects->tweets as $tweet){
			$tempTweets[$tweet->id_str] = new Tweet($tweet, true);
		}

		foreach($json->globalObjects->users as $user){
			$this->tempUsers[$user->id_str] = new User($user, true);
		}

		foreach($json->timeline->instructions as $i){

			if(isset($i->addEntries->entries)){

				foreach($i->addEntries->entries as $instruction){

					if(str_starts_with($instruction->entryId, 'sq-I-t')){
						$this->tempTimeline[$instruction->content->item->content->tweet->id] = null;
					}
					elseif($instruction->entryId === 'sq-cursor-bottom'){
						$lastCursor = $instruction->content->operation->cursor->value;
					}

				}

			}
			elseif(isset($i->replaceEntry->entryIdToReplace) && $i->replaceEntry->entryIdToReplace === 'sq-cursor-bottom'){
				$lastCursor = $i->replaceEntry->entry->content->operation->cursor->value;
			}
			else{
				$lastCursor = '';
			}
		}

		return true;
	}

	/**
	 * @see https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w
	 *
	 * @param string $drilCSV the path to the dril .csv downloaded from google docs
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function parseDrilCSV(string $drilCSV):array{

		if(!file_exists($drilCSV)){
			throw new Exception('cannot open source file');
		}

		// the fields defined in the .csv
		$fields = [
			'name', // always "wint (@dril)"
			'text',
			'date',
			'time',
			'retweets',
			'likes',
			'link',
			'type', // Tweet, Reply, ReTweet
			'image', // No, Yes
			'video', // No, Yes
			'ad',
			'description',
			'location', // always "big burbank"
			'language',
			'source',
		];

		$fh     = fopen($drilCSV, 'r');
		$tweets = [];

		if(!$fh){
			throw new Exception('could not create file handle');
		}

		while(!feof($fh)){
			$data = fgetcsv($fh);

			if($data === false){
				break;
			}

			$tweet = array_combine($fields, $data);

			$tweets[] = [
				'id'    => intval(str_replace('https://twitter.com/dril/status/', '', trim($tweet['link']))),
				'is_rt' => str_starts_with(trim($tweet['text']), 'RT @'),
			];
		}

		fclose($fh);

		$this->logger->info(sprintf('parsed %d tweets from %s', count($tweets), realpath($drilCSV)));

		// remove the first row (header/description)
		array_shift($tweets);

		return $tweets;
	}

	/**
	 * RTs are a mess and the messages are always truncated in the fetched RT status, so we'll need to fetch the original tweets too.
	 * An RT creates a separate status that is saved as old style retweet "RT @username ...", truncated to 140 characters.
	 * Both, v1 and v2 endpoints will only return the truncated text if the RT status id is called.
	 * Only the v2 endpoint returns the id of the original tweet that was retweeted.
	 */
	protected function fetchRTMeta(array $retweets):array{
		$rtIDs = [];

		foreach(array_chunk($retweets, 100) as $i => $ids){

			$v2Params = [
				'ids'          => implode(',', $ids),
				'tweet.fields' => 'author_id,referenced_tweets,conversation_id,created_at',
			];

			$response = $this->httpRequest('/2/tweets', $v2Params, 'meta-v2-tweets-%s');

			if($response === null){
				$this->logger->warning('could not fetch tweets from /2/tweets');

				continue;
			}

			$json = MessageUtil::decodeJSON($response);

			foreach($json->data as $tweet){

				if(!isset($tweet->referenced_tweets)){
					$this->logger->warning(sprintf('does not look like a retweet: "%s"', $tweet->text ?? ''));

					continue;
				}

				$id   = (int)$tweet->referenced_tweets[0]->id;
				$rtID = (int)$tweet->id;
				// create a parsed tweet for the RT status and save the original tweet id in it
				$this->tempTimeline[$rtID] = (new Tweet($tweet, true))->setRetweetedStatusID($id);
				// to backreference in the next op
				// original tweet id => retweet status id
				$rtIDs[$id] = $rtID;
			}

			$this->logger->info(sprintf('[%d] fetched meta for %s tweet(s)', $i, count($ids)));
		}

		return $rtIDs;
	}

	/**
	 * this is even more of a mess as both, the v1 and v2 endpoints don't return the complete data so we're gonna call both
	 */
	protected function fetchRetweets(array $retweets):void{

		// we're gonna fetch the metadata for the retweet status from the v2 endpoint first
		$rtIDs = $this->fetchRTMeta($retweets);

		foreach(array_chunk(array_keys($rtIDs), 100) as $i => $ids){

			$v1Params = [
				'id'                   => implode(',', $ids),
				'trim_user'            => false,
				'map'                  => false,
				'include_ext_alt_text' => true,
				'skip_status'          => true,
				'include_entities'     => true,
			];

			// all the fields! (what a fucking mess)
			$v2Params = [
				'ids'          => implode(',', $ids),
				'expansions'   => 'attachments.poll_ids,attachments.media_keys,author_id,entities.mentions.username,geo.place_id,in_reply_to_user_id,referenced_tweets.id,referenced_tweets.id.author_id',
				'media.fields' => 'duration_ms,height,media_key,preview_image_url,type,url,width,public_metrics,alt_text,variants',
				'place.fields' => 'contained_within,country,country_code,full_name,geo,id,name,place_type',
				'poll.fields'  => 'duration_minutes,end_datetime,id,options,voting_status',
				'tweet.fields' => 'attachments,author_id,conversation_id,created_at,entities,geo,id,in_reply_to_user_id,lang,public_metrics,possibly_sensitive,referenced_tweets,reply_settings,source,text,withheld',
				'user.fields'  => 'created_at,description,entities,id,location,name,pinned_tweet_id,profile_image_url,protected,public_metrics,url,username,verified,withheld',
			];

			$v1Response = $this->httpRequest('/1.1/statuses/lookup.json', $v1Params, 'data-v1-statuses-lookup-%s');
			$v2Response = $this->httpRequest('/2/tweets', $v2Params, 'data-v2-tweets-%s');

			if($v1Response === null || $v2Response === null){
				$this->logger->warning('could not fetch tweets from v1 or v2 endpoints');

				continue;
			}

			$v1json = MessageUtil::decodeJSON($v1Response);
			$v2json = MessageUtil::decodeJSON($v2Response);

			foreach($v1json as $v1Tweet){
				$this->tempUsers[$v1Tweet->user->id] = new User($v1Tweet->user, true);
				$this->tempTimeline[$rtIDs[$v1Tweet->id]]->setRetweetedStatus(new Tweet($v1Tweet, true));
			}

			foreach($v2json->data as $v2Tweet){
				$v2Tweet = new Tweet($v2Tweet, true);
				$rtID    = $rtIDs[$v2Tweet->id];
				$v1Tweet = json_decode(json_encode($this->tempTimeline[$rtID]));

				// @todo: image urls https://twitter.com/<user>/status/<id>>/photo/1
				foreach(['user_id', 'text', 'conversation_id', 'place', 'coordinates', 'geo', 'media'] as $field){
					$v1Tweet->retweeted_status->{$field} = $v2Tweet->{$field};
				}

				$this->tempTimeline[$rtID] = new Tweet($v1Tweet);
			}

			$this->logger->info(sprintf('[%d] fetched data for %s tweet(s)', $i, count($ids)));
		}

	}

	/**
	 * fetch the remaining tweets from the csv
	 */
	protected function fetchCsvTweets(array $csv):void{

		if(empty($csv)){
			return;
		}

		foreach(array_chunk($csv, 100) as $i => $ids){

			$v1Params = [
				'id'                   => implode(',', $ids),
				'trim_user'            => false,
				'map'                  => false,
				'include_ext_alt_text' => true,
				'skip_status'          => true,
				'include_entities'     => true,
			];

			$v1Response = $this->httpRequest('/1.1/statuses/lookup.json', $v1Params, 'csv-v1-statuses-lookup-%s');

			if($v1Response === null){
				$this->logger->warning('could not fetch tweets from v1 endpoint');

				continue;
			}

			$v1json = MessageUtil::decodeJSON($v1Response);

			foreach($v1json as $v1Tweet){
				$this->tempUsers[$v1Tweet->user->id] = new User($v1Tweet->user, true);
				$this->tempTimeline[$v1Tweet->id]    = new Tweet($v1Tweet, true);
			}

			$this->logger->info(sprintf('[%d] fetched data for %s tweet(s) from CSV', $i, count($ids)));
		}

	}

	/**
	 * filter out imporperly embedded photos and tweets
	 *
	 *   - https://twitter.com/<screen_name>/status/<status>/photo/1
	 *   - https://twitter.com/<screen_name>/status/<truncated_status_id>
	 */
	protected function updateEmbeddedMedia():void{
		$matches  = [];
		$statuses = [];

		foreach($this->tempTimeline as $id => $tweet){

			if($tweet === null || $tweet->created_at < $this->since){
				continue;
			}

			$rx = '#https://twitter.com/(?<screen_name>[^/]+)/status/(?<status_id>\d+)/photo/(?<photo>\d+)(\S+)?#i';
			if(preg_match_all($rx, $tweet->text, $m)){
				$matches[$id]  = ['photo', $m];
				$statuses[$id] = $m['status_id'][0];
			}

			if(isset($tweet->retweeted_status->text)){
				if(preg_match_all($rx, $tweet->retweeted_status->text, $m)){
					$matches[$id]  = ['photo_rt', $m];
					$statuses[$id] = $m['status_id'][0];
				}
			}

			if(preg_match_all('#https://twitter.com/(?<screen_name>[^/]+)/status/(?<status_id>\d+)(\S+)?#i', $tweet->text, $m)){
				$matches[$id]  = ['quote', $m];
				$statuses[$id] = $m['status_id'][0];
			}

		}

		$this->logger->info(sprintf('matched %d tweets with embedded media', count($matches)));

		foreach(array_chunk($statuses, 100) as $i => $ids){

			$v1Params = [
				'id'                   => implode(',', $ids),
				'trim_user'            => false,
				'map'                  => false,
				'include_ext_alt_text' => true,
				'skip_status'          => true,
				'include_entities'     => true,
			];

			$v1Response = $this->httpRequest('/1.1/statuses/lookup.json', $v1Params, 'media-v1-statuses-lookup-%s');

			if($v1Response === null){
				$this->logger->warning('could not fetch tweets from v1 endpoint');

				continue;
			}

			$v1json = MessageUtil::decodeJSON($v1Response, false);

			foreach($v1json as $v1Tweet){
				$this->tempUsers[$v1Tweet->user->id] = new User($v1Tweet->user, true);

				$id = array_search($v1Tweet->id, $statuses);

				if(!$id){
					continue;
				}

				[$type, $match] = $matches[$id];

				$tweet = json_decode(json_encode($this->tempTimeline[$id]));

				if($type === 'quote'){
					// in case the quoted tweet does not exist in the tweet object
					if(!isset($tweet->quoted_status_id) || !isset($tweet->quoted_status)){
						$tweet->quoted_status_id = $v1Tweet->id;
						$tweet->quoted_status    = new Tweet($v1Tweet, true);
					}

					// just remove the tweet URL
					$tweet->text = str_replace($match[0][0], '', $tweet->text);
				}
				elseif($type === 'photo' || $type === 'photo_rt'){
					$mediaItems = [];

					if(isset($v1Tweet->entities->media)){
						foreach($v1Tweet->entities->media as $media){
							$mediaItems[] = Tweet::parseMedia($media);
						}
					}
					else{
						// ok this is awkward but there's no media linked in the response, so we gotta go with the link provided :(
						// the v2 response is useless btw
#						var_dump($v1Tweet);
					}

					// add the media to the respective tweets
					if($type === 'photo'){
						$tweet->media = $mediaItems;
						$tweet->text  = str_replace($match[0][0], '', $tweet->text['text']);
					}
					elseif($type === 'photo_rt'){
						$tweet->retweeted_status->media = $mediaItems;
						$tweet->retweeted_status->text  = str_replace($match[0][0], '', $tweet->retweeted_status->text);
					}

				}

				$this->tempTimeline[$id] = new Tweet($tweet);
			}

			$this->logger->info(sprintf('[%d] fetched data for %s embedded/photo tweet(s) ', $i, count($ids)));
		}


	}

	/**
	 * update profiles
	 */
	protected function fetchUserProfiles():void{
		$u = [];

		foreach($this->tempTimeline as $tweet){

			if($tweet ===  null){
				continue;
			}

			$u[$tweet->user_id] = true;

			if($tweet->in_reply_to_user_id !== null){
				$u[$tweet->in_reply_to_user_id] = true;
			}

			if(isset($tweet->retweeted_status)){
				$u[$tweet->retweeted_status->user_id] = true;
			}
		}


		foreach(array_chunk(array_keys($u), 100) as $i => $ids){

			$v1Params = [
				'user_id'          => implode(',', $ids),
				'skip_status'      => true,
				'include_entities' => 'false',
			];

			$v1Response = $this->httpRequest('/1.1/users/lookup.json', $v1Params, 'data-v1-users-lookup-%s');

			if($v1Response === null){
				$this->logger->warning('could not fetch user profiles from v1 endpoint');

				continue;
			}

			$json = MessageUtil::decodeJSON($v1Response);

			$this->logger->info(sprintf('[%d] fetched data for %d user profile(s)', $i, count($json)));

			foreach($json as $user){
				$this->tempUsers[$user->id] = new User($user, true);
			}

		}

	}

}
