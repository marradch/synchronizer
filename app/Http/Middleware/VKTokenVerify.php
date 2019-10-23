<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\VKAuthService;

class VKTokenVerify
{
    public function handle($request, Closure $next)
    {
        $vkAuthService = new VKAuthService();

        $isValidToken = $vkAuthService->checkSessionToken();

        if(!$isValidToken){
            return redirect()->route('home');
        }

        $request->attributes->add(['authData' => session('authData')]);

		return $next($request);
    }
}
