<?php namespace Vinelab\Auth;

use Vinelab\Auth\Social\Network as SocialNetwork;
use Vinelab\Auth\Exception\AuthenticationException;
use Vinelab\Auth\Exception\SocialAccountException;
use Vinelab\Http\Client as HttpClient;

use Najem\Models\Entities\User as UserEntity;

use Eloquent;
use Illuminate\Config\Repository as Config;
use Illuminate\Cache\CacheManager as Cache;
use Illuminate\Http\Response as Response;
use Illuminate\Routing\Redirector as Redirector;

Class Social {

	/**
	 * @var Vinelab\Auth\Social\Network
	 */
	public $network;

	/**
	 * @var Illuminate\Config\Repository
	 */
	protected $config;

	/**
	 * @var Illuminate\Cache\CacheManager
	 */
	protected $cache;

	/**
	 * @var Illuminate\Http\Response
	 */
	protected $response;

	/**
	 * @var  Illuminate\Routing\Redirector
	 */
	protected $redirect;

	/**
	 * @var Eloquent
	 */
	protected $userEntity;

	/**
	 * Keeps track of the request
	 * @var string
	 */
	public $state;

	protected $stateCacheKeyPrefix = 'auth_social_state_';

	function __construct(Config $config,
						 Cache $cache,
						 Redirector $redirector,
						 HttpClient $httpClient,
						 Eloquent $userEntity = null)
	{
		$this->config              = $config;
		$this->cache               = $cache;
		$this->redirect            = $redirector;
		$this->httpClient          = $httpClient;
		$this->userEntity 		   = $userEntity ?: new UserEntity;
	}

	/**
	 *
	 * @param  string $service
	 * @return  Illuminate\Routing\Redirector
	 */
	public function authenticate($service)
	{
		$this->network = $this->networkInstance($service);

		$this->state = $this->state ?: $this->makeState();

		$apiKey = $this->network->settings('api_key');
		$redirectURI = $this->network->settings('redirect_uri');

		$this->cache->put($this->stateCacheKey($this->state), ['api_key'=>$apiKey, 'redirect_uri'=>$redirectURI], 5);

		$url = $this->network->authenticationURL();

		$url = $url.'&'.http_build_query(['state' => $this->state]);

		return $this->redirect->to($url);
	}

	public function authenticationCallback($service, $input)
	{
		$this->network = $this->networkInstance($service);

		// check for state
		if (!isset($input['state']) or empty($input['state']))
		{
			throw new AuthenticationException('state', 'not found');
		}

		$state = $input['state'];

		// verify state existance
		if(!$this->cache->has($this->stateCacheKey($state)))
		{
			throw new AuthenticationException('Timeout', 'Authentication has taken too long, please try again.');
		}

		$accessToken = $this->network->authenticationCallback($input);

		// add access token to cached data and extend to another 5 min
		$cachedStateData = $this->cache->get($this->stateCacheKey($state));
		$cachedStateData['access_token'] = $accessToken;
		$this->cache->put($this->stateCacheKey($state), $cachedStateData, 5);

		$this->saveUser($this->network->profile());
		return ['state'=>$state];
	}

	protected function saveUser($profile)
	{
		if ($profile and isset($profile->email))
		{
			$userFound = $this->userEntity->where('email', '=', $profile->email)->take(1)->get();

			if (count($userFound) === 0)
			{
				$user = $this->userEntity->fill((array) $profile);
				$user->save();

				$socialAccount = [
					'network'      => $this->network->name,
					'account_id'   => $profile->id,
					'access_token' => $profile->access_token
				];

				$user->socialAccounts()->create($socialAccount);
			}
		} else {
			throw new SocialAccountException('Profile', 'Invalid type or structure');
		}
	}

	public function makeState()
	{
		return md5(uniqid(microtime(), true));
	}

	protected function networkInstance($service)
	{
		return new SocialNetwork($service, $this->config, $this->httpClient);
	}

	protected function stateCacheKey($state)
	{
		return $this->stateCacheKeyPrefix.$state;
	}
}