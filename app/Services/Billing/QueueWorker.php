<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Starts a one-shot background queue worker when jobs are waiting, so finalizing an invoice
 * produces its files with just `php artisan serve` running. The worker exits when the queue is empty.
 */
class QueueWorker
{
    /** Seconds before another launch is attempted (a worker needs a moment to claim the job). */
    private const LAUNCH_INTERVAL = 20;

    /**
     * Launch a worker if enabled, a job is waiting and none was launched a moment ago.
     */
    public function ensureRunning(): bool
    {
        if (! config('billing.auto_worker') || ! $this->hasWaitingJobs() || ! Cache::add('billing.queue-worker.launched', now()->timestamp, self::LAUNCH_INTERVAL)) {
            return false;
        }
        try {
            $this->launch();
        } catch (\Throwable $e) {
            // Never break the request; the operator can still use "Coba buat file lagi" or a manual worker.
            Log::warning('Pembuat file invoice gagal dinyalakan otomatis: '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Jobs that are not reserved by a running worker (or whose reservation has expired).
     */
    public function hasWaitingJobs(): bool
    {
        $expired = now()->subSeconds((int) config('queue.connections.database.retry_after', 360))->getTimestamp();

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<=', $expired))
            ->where('available_at', '<=', now()->getTimestamp())
            ->exists();
    }

    /**
     * Start the worker detached from this request.
     */
    public function launch(): void
    {
        $memory = max(256, (int) config('billing.worker_memory_mb', 1024));
        $arguments = array_map('escapeshellarg', [PHP_BINARY, '-d', 'memory_limit='.$memory.'M', base_path('artisan'), 'queue:work', 'database', '--queue=default', '--stop-when-empty', '--tries=3', '--timeout=300', '--memory='.$memory]);
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B '.implode(' ', $arguments).' > NUL 2>&1', 'r'));
        } else {
            exec('nohup '.implode(' ', $arguments).' > /dev/null 2>&1 &');
        }
        Log::info('Pembuat file invoice dinyalakan otomatis.');
    }
}
