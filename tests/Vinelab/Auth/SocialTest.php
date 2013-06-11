<?php namespace Vinelab\Auth\Tests;

use PHPUnit_Framework_TestCase as TestCase;
use Mockery as M;
use Vinelab\Auth\Social;

Class SocialTest extends TestCase {

	public function setUp()
	{
		$this->service = 'facebook';

		$this->apiKey = 'someApiKey';
		$this->redirectURI = 'comeBack';
		$this->authenticationUrl = '123';
		$this->permissions = 'permissions';

		$this->settings = [
			'api_key'            => $this->apiKey,
			'redirect_uri'       => $this->redirectURI,
			'authentication_url' => $this->authenticationUrl,
			'permissions'        => $this->permissions,
			'token_url'          => 'http://token.url.net',
			'secret'             => 'Cannot tell ya',
			'api_url'			 => 'http://api.url.com',
			'profile_uri'		 => '/me'
		];

		$this->mConfig     = M::mock('\Illuminate\Config\Repository');
		$this->mConfig->shouldReceive('get')->andReturn($this->settings);

		$this->mCache      = M::mock('\Illuminate\Cache\CacheManager');
		$this->mCache->shouldReceive('put')->andReturn(true);

		$this->mRedirector = M::mock('\Illuminate\Routing\Redirector');
		$this->mRedirector->shouldReceive('to')->andReturn($this->mRedirector);

		$this->mResponse   = M::mock('\Vinelab\Http\Response');

		$this->mHttpClient = M::mock('\Vinelab\Http\Client');
		$this->mHttpClient->shouldReceive('get')->andReturn($this->mResponse);
	}

	public function testInstantiation()
	{
		$social = new Social($this->mConfig, $this->mCache, $this->mRedirector, $this->mHttpClient);
		$this->assertInstanceOf('Vinelab\Auth\Social', $social);
	}

	public function testMakeState()
	{
		$social = new Social($this->mConfig, $this->mCache, $this->mRedirector, $this->mHttpClient);
		$this->assertNotNull($social->makeState());
	}

	public function testAuthenticationGeneratesState()
	{
		$apiKey      = 'someApiKey';
		$redirectURI = 'someRedirectURI';
		$state       = 'aFakeState';

		$this->mCache->shouldReceive('put')->once();
		$social = new Social($this->mConfig, $this->mCache, $this->mRedirector, $this->mHttpClient);
		$social->authenticate($this->service, $apiKey, $redirectURI);

		$this->assertNotNull($social->state);
	}

	public function testAuthentication()
	{
		$state = 'aFakeState';

		$this->mCache->shouldReceive('put')
			->with($state, ['api_key'=>$this->apiKey, 'redirect_uri'=>$this->redirectURI], 5)
			->once();

		$social = new Social($this->mConfig, $this->mCache, $this->mRedirector, $this->mHttpClient);
		// IMPORTANT! This is put here for testing purposes ONLY, though should never be done this way
		$social->state = $state;
		$authenticate = $social->authenticate($this->service);

		$this->assertNotNull($authenticate);
		$this->assertInstanceOf('\Illuminate\Routing\Redirector', $authenticate);
	}

	/**
	 * @expectedException Vinelab\Auth\Exception\AuthenticationException
	 */
	public function testAuthenticationCallbackWithoutState()
	{
		$s = new Social($this->mConfig, $this->mCache, $this->mRedirector, $this->mHttpClient);
		$s->authenticationCallback($this->service, ['api_key'=>'something']);
	}

	public function testAuthenticationCallback()
	{
		$this->markTestSkipped(
			'Eloquent Class not found when saving using the User Entity..
			kept till package unit testing is improved in laravel'
		);

		$state = 'aFakeState';

		$this->mCache->shouldReceive('get')
			->with($state)
			->andReturn(['api_key'=>$this->apiKey, 'redirect_uri'=>$this->redirectURI]);
		$this->mCache->shouldReceive('has')->andReturn(true);

		$this->mResponse->shouldReceive('json')->andReturn(null);
		$this->mResponse->shouldReceive('content')->andReturn('access_token=123&expires=1234');

		$s = new Social($this->mConfig, $this->mCache, $this->mRedirector, $this->mHttpClient);
		$s->authenticationCallback($this->service, ['state'=>$state, 'code'=>'123', 'api_key'=>$this->apiKey]);
	}

	/**
	 * @expectedException Vinelab\Auth\Exception\AuthenticationException
	 */
	public function testAuthenticationCallbackWithInexistingState()
	{
		$state = 'aFakeState';
		$this->mCache->shouldReceive('has')->andReturn(false);
		$s = new Social($this->mConfig, $this->mCache, $this->mRedirector, $this->mHttpClient);
		$s->authenticationCallback($this->service, ['state'=>$state, 'code'=>'123', 'api_key'=>$this->apiKey]);
	}

}