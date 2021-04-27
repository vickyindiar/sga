<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class CustomAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $auth = 'Epsylon$21';
        $auth2 = $request->header('X-Auth');
        // $AuthorizationCode = '';
        // try {
        //     $AuthorizationCode = Crypt::decrypt($request->header('X-Auth'));
        // } catch (DecryptException $e) {
        //     return response($e);
        // }

        if($auth != $auth2){
             return response(['Not valid token provider.'], 401);
        }
        return $next($request);
    }
}
