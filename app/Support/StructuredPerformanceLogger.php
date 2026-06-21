<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StructuredPerformanceLogger
{
    private bool $active = false;

    private string $requestId;
    private float $startedAt;
    private int $startedMemory;

    private array $requestMeta = [];
    private array $queries = [];
    private array $spans = [];

    private ?array $exception = null;

    public function start(Request $request): void
    {
        $this->active = true;
        $this->requestId = (string) str()->uuid();
        $this->startedAt = microtime(true);
        $this->startedMemory = memory_get_usage(true);

        $this->queries = [];
        $this->spans = [];
        $this->exception = null;

        $this->requestMeta = [
            'request_id' => $this->requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'route_name' => optional($request->route())->getName(),
            'user_id' => optional($request->user())->id,
            'ip' => $request->ip(),
            'container' => gethostname(),
            'pid' => getmypid(),
            'started_at' => now()->toDateTimeString(),
        ];
    }

    public function addQuery(string $sql, array $bindings, float $timeMs): void
    {
        if (!$this->active) {
            return;
        }

        $this->queries[] = [
            'sql' => $sql,
            'bindings' => $this->sanitizeBindings($bindings),
            'time_ms' => round($timeMs, 2),
        ];
    }

    public function span(string $name, Closure $callback, array $meta = [])
    {
        if (!$this->active) {
            return $callback();
        }

        $start = microtime(true);
        $startMemory = memory_get_usage(true);

        $success = true;
        $error = null;

        try {
            return $callback();
        } catch (Throwable $e) {
            $success = false;
            $error = [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ];

            throw $e;
        } finally {
            $this->spans[] = [
                'name' => $name,
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                'memory_delta_mb' => round((memory_get_usage(true) - $startMemory) / 1024 / 1024, 2),
                'success' => $success,
                'error' => $error,
                'meta' => $meta,
            ];
        }
    }

    public function recordException(Throwable $e): void
    {
        $this->exception = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    public function finish(?Response $response = null): void
    {
        if (!$this->active) {
            return;
        }

        $durationMs = round((microtime(true) - $this->startedAt) * 1000, 2);

        $dbTotalTime = array_sum(array_column($this->queries, 'time_ms'));

        $slowQueries = collect($this->queries)
            ->sortByDesc('time_ms')
            ->take(10)
            ->values()
            ->all();

        $slowSpans = collect($this->spans)
            ->sortByDesc('duration_ms')
            ->take(10)
            ->values()
            ->all();

        $payload = [
            'type' => 'request_performance',
            'request' => $this->requestMeta,
            'response' => [
                'status' => $response?->getStatusCode(),
            ],
            'summary' => [
                'duration_ms' => $durationMs,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'memory_delta_mb' => round((memory_get_usage(true) - $this->startedMemory) / 1024 / 1024, 2),
                'db_queries_count' => count($this->queries),
                'db_total_time_ms' => round($dbTotalTime, 2),
                'spans_count' => count($this->spans),
            ],
            'slowest_queries' => $slowQueries,
            'slowest_spans' => $slowSpans,
            'queries' => $this->queries,
            'spans' => $this->spans,
            'exception' => $this->exception,
        ];

        file_put_contents(
            storage_path('logs/performance.jsonl'),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        $this->active = false;
        $this->queries = [];
        $this->spans = [];
        $this->exception = null;
    }

    private function sanitizeBindings(array $bindings): array
    {
        return array_map(function ($value) {
            if (is_string($value) && strlen($value) > 200) {
                return substr($value, 0, 200) . '...';
            }

            return $value;
        }, $bindings);
    }
}
