<?php

namespace App\Http\Middleware;

use Closure;

class CheckWalletAddress
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        //1. check if user logged in
        if (!$request->user()) {
            return redirect('/login');
        }
        //2. check if user has a wallet address
        if (!$request->user()->wallet_address) {
            if ($request->ajax() || $request->wantsJson()) {
                return response([
                    'status' => false,
                    'message' => 'You need to set a wallet address first.',
                    'endpoint' => route('addWalletAddress')
                ], 422);
            }
//            return redirect('/wallet/create');
        }

        return $next($request);
    }
}
