<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DoctorMiddleware
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
        // Check if the user is authenticated and has the 'doctor' role
        if (Auth::check() && Auth::user()->role === 'doctor') {
            return $next($request);
        }

        // If not an doctor, redirect to a suitable page (e.g., home)
        return redirect('/home')->with('error', 'You do not have doctor access.');
    }
}
