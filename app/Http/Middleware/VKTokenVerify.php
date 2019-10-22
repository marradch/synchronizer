<?php

namespace App\Http\Middleware;

use Closure;
use Session;

class VKTokenVerify
{
    public function handle($request, Closure $next)
    {					
        if(!is_array(session('authData'))){
			return redirect()->route('home');
		}
		
		return $next($request);
    }
}
