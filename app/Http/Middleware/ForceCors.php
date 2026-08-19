<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceCors
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin', '');

        $allowed = [
            'https://www.unicaprint.com.br',
            'https://unicaprint.com.br',
            'https://api.unicaprint.com.br',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ];

        $pattern = '/^https?:\/\/([a-z0-9-]+\.)?unicaprint\.com\.br$/';

        $allowedOrigin = '';
        if (in_array($origin, $allowed)) {
            $allowedOrigin = $origin;
        } elseif (preg_match($pattern, $origin)) {
            $allowedOrigin = $origin;
        }

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($allowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept, X-Requested-With');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
