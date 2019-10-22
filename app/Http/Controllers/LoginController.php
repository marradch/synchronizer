<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\VKAuthService;


class LoginController extends Controller
{
	private $vkAuthService;
	
    public function __construct()
	{
		$this->vkAuthService = new VKAuthService();
	}
	
	public function authPage()
	{				
		session(['authData' => 0]);
		//echo '<pre>'; print_r(session('authData')); die;
		if(is_array(session('authData'))){
			return redirect()->route('dashboard');
		}
	
		$loginUrl = $this->vkAuthService->buildLoginUrl();
		
		return view('welcome', [
			'loginUrl' => $loginUrl
		]);
	}
	
	public function authRedirect(Request $request)
	{
		$code = $request->query('code');
		
		$isLoggedIn = $this->vkAuthService->processRedirect($code);
		
		if($isLoggedIn) {
			return redirect()->route('dashboard');
		} else {
			echo 'Авторизация в сциальной сети оказалась не успешной или вы не являетесь администратором группы';
		}
	}
	
	
}
