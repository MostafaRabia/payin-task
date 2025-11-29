<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WrapRequestInTransaction
{
    use DetectsConcurrencyErrors,
        DetectsLostConnections;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $maxAttempts = 2;

        for ($i = 0; $i < $maxAttempts; $i++) {
            DB::beginTransaction();

            $response = $next($request);

            if (property_exists($response, 'exception') && $response->exception) {
                DB::rollBack();

                if ($i < $maxAttempts - 1 && ($this->causedByConcurrencyError($response->exception) || $this->causedByLostConnection($response->exception))) {
                    continue;
                }

                return $response;
            }

            DB::commit();

            return $response;
        }
    }
}
