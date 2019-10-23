<?php

namespace App\Services;

use VK\OAuth\VKOAuth;
use VK\OAuth\VKOAuthDisplay;
use VK\OAuth\Scopes\VKOAuthUserScope;
use VK\OAuth\VKOAuthResponseType;
use VK\Client\VKApiClient;
use App\Token;
use VK\Exceptions\Api\VKApiAuthException;

class VKAuthService
{
	private $appId;
	private $appSecret;
	private $groupId;
	private $redirectURI;
	private $tokenModel;

    /**
     * Create a new instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->appId = env('VK_APP_ID', null);
		$this->appSecret = env('VK_APP_SECRET', null);
		$this->groupId = env('VK_GROUP_ID', null);
        $this->redirectURI = route('vk.redirect_uri');
		$this->tokenModel = new Token();
    }

	public function buildLoginUrl()
	{
		$oauth = new VKOAuth();

		$scope = [ VKOAuthUserScope::MARKET ];
		$state = 'secret_state_code';

		if(!$this->checkOfflineToken()) {
			$scope[] = VKOAuthUserScope::OFFLINE;
		}

		$loginUrl = $oauth->getAuthorizeUrl(
			VKOAuthResponseType::CODE,
			$this->appId,
			$this->redirectURI,
			VKOAuthDisplay::PAGE,
			$scope,
			$state
		);

		return $loginUrl;
	}

	public function processRedirect($code)
	{
		$oauth = new VKOAuth();

		$response = $oauth->getAccessToken($this->appId, $this->appSecret, $this->redirectURI, $code);
		$access_token = $response['access_token'];
		$user_id = $response['user_id'];
		$expires_in = $response['expires_in'];

		$vk = new VKApiClient();
		$response = $vk->groups()->get($access_token, array(
			'user_id' => $user_id,
			'filter' => 'admin',
		));

		if($response['items'] && in_array($this->groupId, $response['items'])) {
			if($expires_in == 0) {
				$token = new Token();
				$token->token = $access_token;
				$token->save();
			}

			$response = $vk->users()->get($access_token);
			$authData = [
				'access_token' => $access_token,
				'user_id' => $user_id,
				'full_name' => $response[0]['first_name'].' '.$response[0]['last_name'],
			];
			session(['authData' => $authData]);

			return true;
		}

		return false;
	}

	private function checkOfflineToken()
	{
		$tokenItem = $this->tokenModel->first();
		if($tokenItem){
			$vk = new VKApiClient();
			try{
				$vk->users()->get($tokenItem->token);

				return true;
			} catch(VKApiAuthException $e) {
				$tokenItem->delete();
			}
		}

		return false;
	}
}
