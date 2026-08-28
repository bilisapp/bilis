<?php

namespace App\Services\Autofix;

use App\Enums\LlmProvider;
use App\Models\FixJob;
use App\Models\Team;
use App\Models\TeamLlmCredential;

/**
 * Resolves the model credential one job runs on.
 *
 * Bring-your-own-key, scoped to a team, and now more than one key per team.
 * That scope is not arbitrary: the model credential is the only thing in a job
 * spec that can cost money rather than merely propose a patch, and it travels
 * to the runner in the clear (the platform offers no per-run secret channel —
 * see Ayos's DEPLOY.md §2). A key belonging to one customer bounds the worst
 * case to that customer's own budget; a shared key would put every customer's
 * spend behind one value on every run record.
 *
 * Resolution has three steps, in this order:
 *
 * 1. The credential pinned on the job. A person picked it in the new-job
 *    dialog, or the scan wrote the team default there when it raised the job.
 * 2. The team's current default, if the pinned one has since been deleted.
 * 3. The instance-wide key in config — the exception, for single-tenant and
 *    self-hosted deployments where "the customer" and "the operator" are the
 *    same party. A multi-tenant instance should not rely on it.
 */
class LlmCredentials
{
    /**
     * The credential to run this job on.
     *
     * @throws AyosException
     */
    public function forJob(FixJob $job): ResolvedLlmCredential
    {
        $pinned = $job->llmCredential;

        if ($pinned instanceof TeamLlmCredential) {
            return $this->fromRow($pinned);
        }

        return $this->forTeam($job->project->team);
    }

    /**
     * The credential this team's jobs run on by default.
     *
     * @throws AyosException
     */
    public function forTeam(Team $team): ResolvedLlmCredential
    {
        $credential = $team->defaultLlmCredential();

        if ($credential instanceof TeamLlmCredential) {
            return $this->fromRow($credential);
        }

        $fallback = $this->configured();

        if ($fallback instanceof ResolvedLlmCredential) {
            return $fallback;
        }

        throw AyosException::missingLlmKey($team);
    }

    /**
     * Whether this team can run a job at all.
     *
     * Asked before a job row is created, so a customer who has not added a key
     * is told plainly rather than watching a job fail two minutes later with
     * something that reads like an outage.
     */
    public function configuredFor(Team $team): bool
    {
        return $team->hasLlmCredential() || $this->configured() instanceof ResolvedLlmCredential;
    }

    /**
     * The instance-wide credential, when one is configured.
     */
    private function configured(): ?ResolvedLlmCredential
    {
        $key = config('autofix.llm.api_key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $provider = LlmProvider::tryFrom((string) config('autofix.llm.provider', LlmProvider::Anthropic->value))
            ?? LlmProvider::Anthropic;

        return new ResolvedLlmCredential($provider, $key);
    }

    /**
     * Turn a stored credential into the shape the job spec is built from.
     */
    private function fromRow(TeamLlmCredential $credential): ResolvedLlmCredential
    {
        return new ResolvedLlmCredential(
            $credential->provider,
            (string) $credential->api_key,
            $credential,
        );
    }
}
