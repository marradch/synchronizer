<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\VKAuthService;
use App\Settings;


class VKAuthController extends Controller
{
	private $vkAuthService;

    public function __construct()
	{
		$this->vkAuthService = new VKAuthService();
	}

	public function authPage()
	{
		if(is_array(session('authData'))){
			return redirect()->route('dashboard');
		}

		$loginUrl = $this->vkAuthService->buildLoginUrl();

		return view('vk_auth.welcome', [
			'loginUrl' => $loginUrl
		]);
	}

	public function authRedirect(Request $request)
	{
		$code = $request->query('code');

		$redirectTo = $this->vkAuthService->processRedirect($code);

		return redirect()->to($redirectTo);
	}

	public function chooseGroup()
    {
        $groupsResponse = $this->vkAuthService->getGroupsListForCurrentUser();

        if($groupsResponse['count']) {
            return view('vk_auth.set_group', [
                'groups' => $groupsResponse['items']
            ]);
        }
    }

    public function setGroup(Request $request)
    {
        $group_id = $request->post('group_id');

        $settingsItem = new Settings();
        $settingsItem->name = 'group';
        $settingsItem->value = $group_id;
        $settingsItem->save();

        return redirect()->route('dashboard');
    }

    public function logout()
    {
        session(['authData' => 0]);
        return redirect()->route('home');
    }

    public function displayError($code)
    {
        $errorText = '';
        switch ($code) {
            case 'group_member':
                $errorText = 'Вы не являетесь администратором группы указанной в настройках синхронизатора';
                break;
            default:
                $errorText = 'error';
        }

        return view('vk_auth.errors', [
            'errorText' => $errorText
        ]);
    }
}
