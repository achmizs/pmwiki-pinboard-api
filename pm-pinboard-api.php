<?php if (!defined('PmWiki')) exit();
/** \pm-pinboard-api.php
  * \Copyright 2018-2021 Said Achmiz
  * \Licensed under the MIT License
  * \brief Provides an easy-to-use PmWiki-compatible interface to the Pinboard API.
  */
$RecipeInfo['PmPinboardAPI']['Version'] = '2021-12-12';

/*************/
/* VARIABLES */
/*************/

SDV($PinboardAPIEndpoint, 'https://api.pinboard.in/v1/');
SDV($PinboardAPIToken, 'YOUR_API_TOKEN_GOES_HERE');
SDVA($PinboardAPIAllowedMethods, [
	'posts/update',
// 	'posts/add',
// 	'posts/delete',
	'posts/get',
	'posts/recent',
	'posts/dates',
	'posts/all',
	'posts/suggest',
	
	'tags/get',
// 	'tags/delete',
// 	'tags/rename',

	'user/secret',

	'notes/list',
	'notes/ID'
]);
SDVA($PinboardAPIMethodCooldowns, [
	'global'		=>	  3,
	'posts/recent'	=>	 60,
	'posts/all'		=>	300
]);
SDV($PinboardAPICacheFolder, 'pub/cache/pinboard/');
SDV($PinboardAPIResponseCacheDuration, 0);
$PinboardAPIResponseCacheDuration = ($PinboardAPIResponseCacheDuration > 0) ?
									 max($PinboardAPIResponseCacheDuration, max($PinboardAPIMethodCooldowns)) :
									 0;

/*********/
/* SETUP */
/*********/

if (!file_exists($PinboardAPICacheFolder))
	mkdir($PinboardAPICacheFolder);

$response_cache_file = $PinboardAPICacheFolder."response_cache.json";	
$response_cache = PinboardAPIGetResponseCache();

$request_log_file = $PinboardAPICacheFolder."request_log.json";
$request_log = PinboardAPIGetRequestLog();
	
/*************/
/* FUNCTIONS */
/*************/

function PinboardAPIRequest($method, $params) {
	global $PinboardAPIToken, $PinboardAPIEndpoint, $PinboardAPIAllowedMethods,
		$PinboardAPIResponseCacheDuration, $PinboardAPIMethodCooldowns;	
	global $request_log, $response_cache;

	## Check if specified method is allowed. If not, return an error.
	if (!(in_array($method, $PinboardAPIAllowedMethods) || 
		 (in_array('notes/ID', $PinboardAPIAllowedMethods) && preg_match("^notes\/", $method))))
		return [
			'error-text' => "The method “$method” is not permitted.",
			'error-html' => "<p style='color: red; font-weight: bold;'>The method “<code>$method</code>” is not permitted.</p>\n"
		];

	## Build the request.
	$request_URL = $PinboardAPIEndpoint . $method;
	$request_params = [
		'format'		=>	'json',
		'auth_token'	=>	$PinboardAPIToken
	];
	$request_params = array_merge($request_params, $params);
	$request_URL .= "?" . http_build_query($request_params);

	## This is for logging/caching.
	$request_URL_hash = md5($request_URL);	
	$cur_time = time();
	
	## If...
	## a) a response cache duration is specified, and...
	## b) there is a cached response for this request, and...
	## c) the cached response isn't too old...
	## ... then return the cached response.
	if ($PinboardAPIResponseCacheDuration > 0 && 
		isset($response_cache[$request_URL_hash]) &&
		($cur_time - $response_cache[$request_URL_hash]['request_time']) <= $PinboardAPIResponseCacheDuration)
	{
		return $response_cache[$request_URL_hash];
	}

	## Check elapsed time since last request (or last request of this type, in the case of
	## methods that have their own rate limits (i.e. posts/recent and posts/all)).
	## If the new request comes too soon after the last one, return an error message.
	$cooldown_category = $PinboardAPIMethodCooldowns[$method] ? $method : 'global';			  
	$cooldown = $PinboardAPIMethodCooldowns[$cooldown_category];
	$elapsed = $cur_time - $request_log["last-{$cooldown_category}"];
	## Alternatively, if the last request got an HTTP status code 429 (Too Many Requests),
	## then make sure a good long while has elapsed since the last request of any kind;
	## if it hasn't, then return an error.
	## (What's a "good long while"? Well, "twice as long as the longest cooldown" seems
	## like a reasonable value. (The longest cooldown should be the 5-minute cooldown for
	## posts/all, so the cooldown after a 429 ought to be 10 minutes (600 seconds).))
	if (isset($request_log['last_request_hash']) && 
		$response_cache[$request_log['last_request_hash']]['http_code'] == 429) {
		$cooldown = 2 * max($PinboardAPIMethodCooldowns);
		$elapsed = $cur_time - $request_log["last-global"];
	}
	## In either case, if we're still within the relevant cooldown, return an error.
	if ($elapsed < $cooldown)
		return [
		'error-text' => "Too many requests. Wait a bit, then try again.",
		'error-html' => "<p style='color: red; font-weight: bold;'>Too many requests. Wait a bit, then try again.</p>\n"
		];
	
	## Send the request.
	$curl = curl_init($request_URL);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	$curl_response = curl_exec($curl);
	
	## Handle the response.
	$info = curl_getinfo($curl);
	$response = json_decode($curl_response, true);
	$response['http_code'] = $info['http_code'];
	$response['request_time'] = $cur_time;
	curl_close($curl);
	
	## Cache the response.
	PinboardAPIUpdateResponseCache($request_URL_hash, $response);

	## Update the last-request info.
	$request_log['last-global'] = $cur_time;
	if ($method == 'posts/recent') $request_log['last-posts/recent'] = $cur_time;
	else if ($method == 'posts/all') $request_log['last-posts/all'] = $cur_time;
	$request_log['last_request_hash'] = $request_URL_hash;
	PinboardAPISaveRequestLog($request_log);
	
	return $response;
}

/***************/
/* REQUEST LOG */
/***************/

function PinboardAPIResetRequestLog() {
	global $request_log_file;
	file_put_contents($request_log_file, json_encode([
		'last-global' 		=>	0,
		'last-posts/recent'	=>	0,
		'last-posts/all'	=>	0
	]));
}

function PinboardAPIGetRequestLog() {
	global $request_log_file;
	if (!file_exists($request_log_file))
		PinboardAPIResetRequestLog();

	$request_log = json_decode(file_get_contents($request_log_file), true);
	return $request_log;
}

function PinboardAPISaveRequestLog($request_log) {
	global $request_log_file;
	file_put_contents($request_log_file, json_encode($request_log));
}

/******************/
/* RESPONSE CACHE */
/******************/

function PinboardAPIClearResponseCache() {
	global $response_cache_file;
	file_put_contents($response_cache_file, json_encode([ ]));
}

function PinboardAPIGetResponseCache() {
	global $response_cache_file;
	if (!file_exists($response_cache_file)) {
		PinboardAPIClearResponseCache();
		return [ ];
	} else {
		return json_decode(file_get_contents($response_cache_file), true);
	}
}

function PinboardAPIUpdateResponseCache($request_URL_hash, $response) {
	global $response_cache_file, $PinboardAPIResponseCacheDuration;
	
	$response_cache = ($PinboardAPIResponseCacheDuration > 0) ? PinboardAPIGetResponseCache() : [ ];
	$response_cache[$request_URL_hash] = $response;
	file_put_contents($response_cache_file, json_encode($response_cache));
}
