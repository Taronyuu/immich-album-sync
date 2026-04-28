<?php

namespace App\Sync;

use App\Models\JobRun;
use Illuminate\Support\Facades\Log;

class RunLogger
{
    private const FLUSH_LINE_THRESHOLD = 20;
    private const FLUSH_INTERVAL_SECONDS = 2.0;

    private array $buffer = [];
    private float $lastFlush = 0.0;

    public function __construct(private readonly JobRun $run)
    {
        $this->lastFlush = microtime(true);
    }

    public function info(string $message, array $context = []): void
    {
        $this->emit('INFO', $message, $context);
        Log::info($message, ['job_run_id' => $this->run->id] + $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->emit('WARN', $message, $context);
        Log::warning($message, ['job_run_id' => $this->run->id] + $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->emit('ERROR', $message, $context);
        Log::error($message, ['job_run_id' => $this->run->id] + $context);
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $appended = implode("\n", $this->buffer) . "\n";
        $this->buffer = [];
        $this->lastFlush = microtime(true);

        $this->run->log = ($this->run->log ?? '') . $appended;
        $this->run->save();
    }

    private function emit(string $level, string $message, array $context): void
    {
        $line = '[' . now()->format('H:i:s') . "] [{$level}] " . $message;

        if (! empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->buffer[] = $line;

        if (
            count($this->buffer) >= self::FLUSH_LINE_THRESHOLD
            || (microtime(true) - $this->lastFlush) >= self::FLUSH_INTERVAL_SECONDS
        ) {
            $this->flush();
        }
    }
}
