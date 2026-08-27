<?php

namespace App\Services\Autofix;

/**
 * Turns one error log row into a stable identity for the error behind it.
 *
 * The same bug logged a thousand times, from three deploys living at three
 * different release paths, with line numbers that shift on every edit, has to
 * fingerprint to one value — otherwise the trigger would raise a fresh fix job
 * for every rebuild. So everything volatile is normalised away before hashing:
 * line and column numbers, hex addresses, hashes and ids, and the absolute
 * prefix in front of a file path.
 *
 * The input is a row as `LogQuery` returns it, but every accessor is tolerant:
 * a record may carry OTel `exception.*` attributes, or it may be nothing but a
 * `Body` string with a stack trace in it, which is what most simple-ingest
 * clients send.
 *
 * Pure and deterministic — no clock, no config, no database.
 *
 * @phpstan-type ErrorRecord array<string, mixed>
 */
class ErrorFingerprinter
{
    /**
     * How many normalised frames take part in the identity.
     *
     * Deep frames are shared by unrelated errors (the framework's kernel is at
     * the bottom of every PHP stack), so only the top of the stack carries
     * signal. Five is enough to separate two failures inside the same method.
     */
    public const FRAME_DEPTH = 5;

    /**
     * The attribute keys an exception class may arrive under.
     *
     * `exception.type` is the OTel semantic convention; the others are what
     * Monolog-shaped and hand-rolled clients actually send.
     *
     * @var list<string>
     */
    private const CLASS_KEYS = [
        'exception.type',
        'exception.class',
        'exception_class',
        'error.type',
        'exception',
        'error',
    ];

    /**
     * The attribute keys a stack trace may arrive under.
     *
     * @var list<string>
     */
    private const STACK_KEYS = [
        'exception.stacktrace',
        'exception.stack_trace',
        'exception.trace',
        'exception_trace',
        'error.stack',
        'stacktrace',
        'stack',
        'trace',
    ];

    /**
     * The attribute keys an exception message may arrive under.
     *
     * @var list<string>
     */
    private const MESSAGE_KEYS = [
        'exception.message',
        'exception_message',
        'error.message',
        'message',
    ];

    /**
     * A fully qualified class name that reads like an exception type.
     */
    private const CLASS_PATTERN = '/(?<![A-Za-z0-9_\\\\])((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error|Throwable|Fault))(?![A-Za-z0-9_])/';

    /**
     * A `Class: message` opener, which is how most runtimes print a throwable.
     *
     * This is the fallback for class names that do not advertise themselves
     * with an `Exception`-ish suffix — `App\\Exceptions\\PaymentFailed` is a
     * perfectly ordinary thing for an application to raise.
     */
    private const HEADLINE_PATTERN = '/^[ \t]*((?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*|[A-Z][A-Za-z0-9_]*)[ \t]*:[ \t]/m';

    /**
     * How many trailing path segments survive normalisation.
     *
     * Enough to tell `app/Services/Billing/Invoice.php` from
     * `app/Http/Billing/Invoice.php`, few enough that a release directory,
     * a container mount point or a developer's home directory drops off.
     */
    private const PATH_SEGMENTS = 3;

    /**
     * Compute the stable fingerprint for one error record.
     *
     * @param  ErrorRecord  $logRecord
     */
    public function fingerprint(array $logRecord): string
    {
        $frames = $this->stackFrames($logRecord);

        /*
         * A record with no usable stack still has to fingerprint to something
         * stable, so the message itself is normalised into the identity. It is
         * a weaker signal than a stack, which is why it is only ever the
         * fallback.
         */
        $tail = $frames === []
            ? ['message:'.$this->normalizeMessage($this->message($logRecord))]
            : $frames;

        return hash('sha256', implode("\n", [
            'service:'.$this->serviceName($logRecord),
            'class:'.$this->exceptionClass($logRecord),
            ...$tail,
        ]));
    }

    /**
     * Read the service the error was logged by.
     *
     * @param  ErrorRecord  $logRecord
     */
    public function serviceName(array $logRecord): string
    {
        foreach (['serviceName', 'ServiceName', 'service_name', 'service'] as $key) {
            $value = $logRecord[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Read the exception class, falling back to reading it out of the body.
     *
     * @param  ErrorRecord  $logRecord
     */
    public function exceptionClass(array $logRecord): string
    {
        $attribute = $this->attribute($logRecord, self::CLASS_KEYS);

        if ($attribute !== null && preg_match(self::CLASS_PATTERN, $attribute, $matches) === 1) {
            return $matches[1];
        }

        if ($attribute !== null && $this->looksLikeClassName($attribute)) {
            return trim($attribute);
        }

        $haystack = $this->body($logRecord)."\n".($this->attribute($logRecord, self::MESSAGE_KEYS) ?? '');

        if (preg_match(self::CLASS_PATTERN, $haystack, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match(self::HEADLINE_PATTERN, $haystack, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Read the human readable error message.
     *
     * @param  ErrorRecord  $logRecord
     */
    public function message(array $logRecord): string
    {
        $message = $this->attribute($logRecord, self::MESSAGE_KEYS);

        if ($message !== null && trim($message) !== '') {
            return trim($message);
        }

        $body = trim($this->body($logRecord));
        $firstLine = trim((string) strtok($body, "\n"));

        return $firstLine === '' ? $body : $firstLine;
    }

    /**
     * Read the raw stack trace text, from attributes or from the body.
     *
     * @param  ErrorRecord  $logRecord
     */
    public function stackTrace(array $logRecord): string
    {
        $stack = $this->attribute($logRecord, self::STACK_KEYS);

        if ($stack !== null && trim($stack) !== '') {
            return trim($stack);
        }

        return trim($this->body($logRecord));
    }

    /**
     * The normalised top frames the fingerprint is built from.
     *
     * @param  ErrorRecord  $logRecord
     * @return list<string>
     */
    public function stackFrames(array $logRecord): array
    {
        $frames = [];

        foreach (preg_split('/\r\n|\r|\n/', $this->stackTrace($logRecord)) ?: [] as $line) {
            $frame = $this->frame($line);

            if ($frame === null) {
                continue;
            }

            $frames[] = $frame;

            if (count($frames) === self::FRAME_DEPTH) {
                break;
            }
        }

        return $frames;
    }

    /**
     * Normalise a single stack line, or null when it is not a frame.
     *
     * Recognises the two shapes that actually show up in Bilis: PHP's
     * `#0 /path/file.php(12): Class->method()` and the V8 style
     * `at fn (/path/file.js:12:34)` that JavaScript, TypeScript and most
     * runtimes copying it emit.
     */
    private function frame(string $line): ?string
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $isPhpFrame = preg_match('/^#\d+\s+/', $line) === 1;
        $isCallerFrame = preg_match('/^at\s+/i', $line) === 1;

        if (! $isPhpFrame && ! $isCallerFrame) {
            return null;
        }

        $line = (string) preg_replace(['/^#\d+\s+/', '/^at\s+/i'], '', $line);

        return $this->normalizeFrame($line);
    }

    /**
     * Strip everything volatile out of one frame.
     */
    private function normalizeFrame(string $frame): string
    {
        $frame = $this->normalizeVolatileTokens($frame);

        /*
         * Line and column numbers move with every edit above them, and the
         * same call site is the same call site whichever line it now sits on.
         */
        $frame = (string) preg_replace('/\((\d+)(?::(\d+))?\)/', '', $frame);
        $frame = (string) preg_replace('/:\d+(?::\d+)?(?=[\s)\]]|$)/', '', $frame);

        $frame = (string) preg_replace_callback(
            '#(/[^\s(){}\[\],:]+)#',
            fn (array $matches): string => $this->normalizePath($matches[1]),
            $frame,
        );

        return trim((string) preg_replace('/\s+/', ' ', $frame));
    }

    /**
     * Reduce an absolute path to its trailing segments.
     *
     * `/var/www/releases/20260827093000/app/Foo.php` and
     * `/home/sam/code/app/app/Foo.php` are the same file to two deploys of the
     * same code, so only the tail is kept.
     */
    private function normalizePath(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return $path;
        }

        return implode('/', array_slice($segments, -self::PATH_SEGMENTS));
    }

    /**
     * Normalise a message into something that survives its variable parts.
     */
    private function normalizeMessage(string $message): string
    {
        $message = $this->normalizeVolatileTokens($message);

        $message = (string) preg_replace_callback(
            '#(/[^\s(){}\[\],:]+)#',
            fn (array $matches): string => $this->normalizePath($matches[1]),
            $message,
        );

        /*
         * Bare numbers in a message are almost always the variable half of it
         * ("user 4192 not found"), so they collapse to one placeholder.
         */
        $message = (string) preg_replace('/\b\d+\b/', 'N', $message);

        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    /**
     * Replace the tokens that differ between two occurrences of one error.
     */
    private function normalizeVolatileTokens(string $value): string
    {
        return (string) preg_replace(
            [
                '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
                '/\b0x[0-9a-f]+\b/i',
                '/\b[0-9a-f]{8,}\b/i',
            ],
            ['UUID', 'ADDR', 'HASH'],
            $value,
        );
    }

    /**
     * Read the first attribute present under any of the given keys.
     *
     * Log attributes win over resource attributes, because the error belongs
     * to the record rather than to the process that emitted it.
     *
     * @param  ErrorRecord  $logRecord
     * @param  list<string>  $keys
     */
    private function attribute(array $logRecord, array $keys): ?string
    {
        foreach ([$this->attributes($logRecord, 'logAttributes'), $this->attributes($logRecord, 'resourceAttributes')] as $bag) {
            foreach ($keys as $key) {
                $value = $bag[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Read one attribute map off the record, in either casing.
     *
     * @param  ErrorRecord  $logRecord
     * @return array<string, string>
     */
    private function attributes(array $logRecord, string $key): array
    {
        $bag = $logRecord[$key] ?? $logRecord[ucfirst($key)] ?? null;

        if (! is_array($bag)) {
            return [];
        }

        $attributes = [];

        foreach ($bag as $name => $value) {
            if (is_string($value)) {
                $attributes[(string) $name] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Read the log body in either casing.
     *
     * @param  ErrorRecord  $logRecord
     */
    private function body(array $logRecord): string
    {
        $body = $logRecord['body'] ?? $logRecord['Body'] ?? '';

        return is_string($body) ? $body : '';
    }

    /**
     * Determine whether a value reads like a bare class name.
     */
    private function looksLikeClassName(string $value): bool
    {
        return preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/', trim($value)) === 1;
    }
}
