<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\VKAuthService;


class DashboardController extends Controller
{	
	
    public function __construct()
	{
		
	}
	
	public function index()
	{		
		echo 'test dashboard - display some values and menu';
		echo '<pre>'; print_r(session('authData')); die;
	}
	
	
}
