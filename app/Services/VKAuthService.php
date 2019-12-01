<?php

namespace App\Services;

use VK\OAuth\VKOAuth;
use VK\OAuth\VKOAuthDisplay;
use VK\OAuth\Scopes\VKOAuthUserScope;
use VK\OAuth\VKOAuthResponseType;
use VK\Client\VKApiClient;
use App\Token;
use App\Settings;
use VK\Exceptions\Api\VKApiAuthException;

class VKAuthService
{
    private CONST DASHBOARD_ROUTE = 'dashboard';
    private CONST ERROR_MEMBER_ROUTE = 'auth.error';
    private CONST SETTINGS_GROUP = 'auth.choose.group';

    private $appId;
    private $appSecret;
    private $redirectURI;
    private $tokenModel;
    private $vk;

    /**
     * Create a new instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->appId = env('VK_APP_ID', null);
        $this->appSecret = env('VK_APP_SECRET', null);
        $this->redirectURI = route('vk.redirect_uri');
        $this->tokenModel = new Token();
        $this->vk = new VKApiClient();
    }

    public function buildLoginUrl()
    {
        $oauth = new VKOAuth();

        $scope = [
            VKOAuthUserScope::MARKET,
            VKOAuthUserScope::PHOTOS
        ];

        $state = 'secret_state_code';

        if (!$this->checkOfflineToken()) {
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

        sleep(1);

        return $loginUrl;
    }

    public function processRedirect($code)
    {
        $oauth = new VKOAuth();
        $response = $oauth->getAccessToken($this->appId, $this->appSecret, $this->redirectURI, $code);
        sleep(1);
        $redirectTo = $this->detectRedirectRouteDependOnGroup($response);

        if ($redirectTo == static::ERROR_MEMBER_ROUTE) {
            return route($redirectTo, ['group_member']);
        }

        $this->processAuth($response);

        return route($redirectTo);
    }

    public function checkOfflineToken()
    {
        $tokenItem = $this->tokenModel->first();
        if ($tokenItem) {
            try {
                $this->vk->users()->get($tokenItem->token);

                return true;
            } catch (VKApiAuthException $e) {
                $tokenItem->delete();
            }
        }

        return false;
    }

    public function checkSessionToken()
    {
        $authData = session('authData');

        if(!is_array($authData) || empty($authData['access_token'])){
            return false;
        }

        $authToken = $authData['access_token'];

        try {
            $this->vk->users()->get($authToken);
            sleep(1);
        } catch (VKApiAuthException $e) {
            return false;
        }

        return true;
    }

	private function detectRedirectRouteDependOnGroup($response)
    {
        $access_token = $response['access_token'];
        $user_id = $response['user_id'];

        $group = Settings::where('name', 'group')->first();

        $redirectTo = '';

        if($group) {
            $response = $this->vk->groups()->get($access_token, array(
                'user_id' => $user_id,
                'filter' => 'admin',
            ));

            if(in_array($group->value, $response['items'])) {
                $redirectTo = static::DASHBOARD_ROUTE;
            } else {
                $redirectTo = static::ERROR_MEMBER_ROUTE;
            }
        } else {
            $redirectTo = static::SETTINGS_GROUP;
        }

        return $redirectTo;
    }

    private function processAuth($response)
    {
        $access_token = $response['access_token'];
        $user_id = $response['user_id'];
        $expires_in = $response['expires_in'];

        if($expires_in == 0) {
            $token = new $this->tokenModel();
            $token->token = $access_token;
            $token->save();
        }

        $response = $this->vk->users()->get($access_token);
        $authData = [
            'access_token' => $access_token,
            'user_id' => $user_id,
            'full_name' => $response[0]['first_name'].' '.$response[0]['last_name'],
        ];
        session(['authData' => $authData]);
    }

    public function getGroupsListForCurrentUser()
    {
        $authData = session('authData');

        $response = $this->vk->groups()->get($authData['access_token'], array(
            'user_id' => $authData['user_id'],
            'filter' => 'admin',
            'extended' => 1
        ));

        sleep(1);

        return $response;
    }
}
