<?php

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Ships Monolog records to a Bilis simple JSON ingest endpoint.
 *
 * Records are buffered in memory and sent as a single POST — the endpoint
 * accepts a JSON array of records — when the buffer fills up, when Monolog
 * closes the handler at shutdown, or when the application terminates (the
 * factory registers that hook, so under FPM/Octane the request has already
 * been answered by the time the POST happens).
 *
 * Logging must never be able to break the application that logs, so every
 * transport failure — timeout, refused connection, non-2xx, unencodable
 * payload — is swallowed and the buffered batch is dropped. There is no
 * retry: a failing sink would otherwise stall every request behind it.
 */
class BilisHandler extends AbstractProcessingHandler
{
    /**
     * The records waiting to be shipped, already mapped to the wire shape.
     *
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    /**
     * The service name reported with every record.
     */
    private readonly string $service;

    /**
     * @param  string  $endpoint  Absolute URL of the simple ingest endpoint.
     * @param  string  $apiKey  Project API key, sent as a bearer token.
     * @param  float  $timeout  Seconds to wait on the ingest call, kept short on purpose.
     * @param  int  $maxBufferSize  Records held before the buffer is flushed.
     * @param  string|null  $service  Defaults to the application name.
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        int|string|Level $level = Level::Debug,
        private readonly float $timeout = 2.0,
        private readonly int $maxBufferSize = 500,
        ?string $service = null,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->service = $service ?? (string) config('app.name');
    }

    /**
     * Send everything buffered so far as one batch.
     *
     * Flushing an empty buffer makes no HTTP call at all, which is what keeps
     * a double flush (close() followed by the terminating hook, say) from
     * shipping anything twice. The buffer is cleared before the call so a
     * failure drops the batch instead of replaying it.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $batch = $this->buffer;
        $this->buffer = [];

        $this->send($batch);
    }

    /**
     * Flush on shutdown; Monolog calls this for every registered handler.
     */
    public function close(): void
    {
        $this->flush();

        parent::close();
    }

    /**
     * The last resort flush, for a handler that is dropped without a close().
     */
    public function __destruct()
    {
        try {
            $this->flush();
        } catch (Throwable) {
            // A destructor must never throw, and a lost log line is not worth one.
        }

        parent::__destruct();
    }

    /**
     * Buffer one record, flushing when the buffer is full.
     */
    protected function write(LogRecord $record): void
    {
        $this->buffer[] = $this->map($record);

        if (count($this->buffer) >= $this->maxBufferSize) {
            $this->flush();
        }
    }

    /**
     * Map a Monolog record onto the simple ingest shape.
     *
     * Monolog's level names are already Bilis severity aliases once
     * lowercased (`warning`, `notice`, `critical`, `emergency`, …), so
     * `App\Services\Ingest\LogSeverity` resolves them without a lookup table
     * of our own. Processor output (`extra`) is merged into `context` under an
     * `extra.` prefix so it can never shadow a key the caller passed.
     *
     * @return array<string, mixed>
     */
    private function map(LogRecord $record): array
    {
        $context = $record->context;

        foreach ($record->extra as $key => $value) {
            $context['extra.'.$key] = $value;
        }

        return [
            'message' => $record->message,
            'level' => strtolower($record->level->getName()),
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'service' => $this->service,
            'context' => $context,
        ];
    }

    /**
     * POST one batch, swallowing every possible failure.
     *
     * @param  list<array<string, mixed>>  $batch
     */
    private function send(array $batch): void
    {
        try {
            Http::asJson()
                ->withToken($this->apiKey)
                ->timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->post($this->endpoint, $batch);
        } catch (Throwable $exception) {
            /*
             * Nothing here may reach the application's logger: this handler is
             * often part of the stack that would receive that line.
             */
            error_log('Bilis log shipping failed: '.$exception->getMessage());
        }
    }
}
