<?php

namespace App\Services\Autofix;

/**
 * What the validator decided about one diff.
 *
 * Three outcomes, and the middle one is the interesting one: a diff that does
 * not apply because the branch moved is not the agent's fault, so the job is
 * offered one more run against a fresh base before it is rejected.
 */
class DiffValidationResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason,
        private readonly ?AppliedDiff $applied,
    ) {}

    /**
     * The diff holds up and has been applied in memory.
     */
    public static function valid(AppliedDiff $applied): self
    {
        return new self('valid', null, $applied);
    }

    /**
     * The diff is refused; no GitHub write may happen.
     */
    public static function rejected(string $reason): self
    {
        return new self('rejected', $reason, null);
    }

    /**
     * The diff is stale; the job earns one fresh run.
     */
    public static function redispatch(string $reason): self
    {
        return new self('redispatch', $reason, null);
    }

    public function isValid(): bool
    {
        return $this->outcome === 'valid';
    }

    public function isRejected(): bool
    {
        return $this->outcome === 'rejected';
    }

    public function isRedispatch(): bool
    {
        return $this->outcome === 'redispatch';
    }

    /**
     * The applied change set, present only on a valid result.
     */
    public function applied(): ?AppliedDiff
    {
        return $this->applied;
    }
}
