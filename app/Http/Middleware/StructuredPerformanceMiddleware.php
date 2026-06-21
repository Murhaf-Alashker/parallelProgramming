<?php

namespace App\Http\Middleware;

use App\Support\StructuredPerformanceLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StructuredPerformanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $logger = app(StructuredPerformanceLogger::class);

        $logger->start($request);

        $response = null;

        try {
            $response = $next($request);

            return $response;
        } catch (Throwable $e) {
            $logger->recordException($e);

            throw $e;
        } finally {
            $logger->finish($response);
        }
    }
}
