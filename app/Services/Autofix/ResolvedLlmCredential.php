<?php

namespace App\Services\Autofix;

use App\Enums\LlmProvider;
use App\Models\TeamLlmCredential;

/**
 * The model credential one run will authenticate with.
 *
 * A resolved credential is not necessarily a row: a single-tenant or
 * self-hosted deployment can configure one key in the environment, and that
 * path has no team to own it. Both shapes carry the same three things the job
 * spec needs — which provider, which key, and the hostname the key is valid at
 * — so `AyosClient` never has to ask which kind it got.
 */
final readonly class ResolvedLlmCredential
{
    public function __construct(
        public LlmProvider $provider,
        public string $key,
        public ?TeamLlmCredential $credential = null,
    ) {}

    /**
     * The API hostname this credential authenticates against.
     */
    public function host(): string
    {
        return $this->provider->host();
    }

    /**
     * Note that a job was dispatched on this credential, when there is a row
     * to note it on.
     */
    public function markUsed(): void
    {
        $this->credential?->markUsed();
    }
}
