<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * The sales API is reachable only through the Worker's SalesGateway entrypoint
 * (a service binding from the sales.clapslearn.in Worker). The public Worker
 * route returns 404 for /api/sales and strips this header, and the gateway sets
 * it to the container's internal token, which never leaves Cloudflare.
 */
class SalesGatewayOnly
{
    public const HEADER = 'X-Claps-Sales-Gateway';

    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('services.sales_gateway.token', '');
        $given = (string) $request->headers->get(self::HEADER, '');
        abort_unless($expected !== '' && $given !== '' && hash_equals($expected, $given), 404);

        return $next($request);
    }
}
