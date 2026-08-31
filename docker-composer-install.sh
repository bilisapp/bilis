#!/usr/bin/env sh
set -eu

# Install the production PHP dependencies, defensively.
#
# Every `dist` archive of a GitHub-hosted package comes from
# codeload.github.com, which intermittently answers HTTP 400 for a legacy.zip
# it served fine a second earlier — and answers it far more often to an
# unauthenticated datacenter IP than to a laptop. Composer 2.10 treats a failed
# dist download as fatal ("Source fallback is disabled"), so a single bad
# response fails the whole image build and there is no deploy. Three defences,
# cheapest first:
#
#   1. Authenticate, when a token is available. An authenticated download is
#      rate-limited per account rather than per IP, which is the difference
#      between "shared with the rest of the datacenter" and "ours".
#   2. Retry with backoff. Composer's package cache is a BuildKit cache mount,
#      so every attempt keeps what the last one managed to download and the
#      retry is shorter than the attempt before it.
#   3. Change transport for the final attempt. `--prefer-source` clones from
#      github.com over git and never touches codeload; it is minutes slower,
#      and a slow deploy beats no deploy.
#
# Tunables (build args / environment): COMPOSER_INSTALL_ATTEMPTS,
# GITHUB_TOKEN.

ATTEMPTS="${COMPOSER_INSTALL_ATTEMPTS:-5}"

# A git clone that stops to ask for a username hangs the build until the
# platform's build timeout kills it. Fail instead.
export GIT_TERMINAL_PROMPT=0

# The token may arrive as a build arg (Coolify's only knob) or as a BuildKit
# secret. Neither reaches the final image: this runs in a stage the production
# image copies `vendor` out of, and nothing else.
token="${GITHUB_TOKEN:-}"
if [ -z "$token" ] && [ -s /run/secrets/github_token ]; then
    token="$(cat /run/secrets/github_token)"
fi

if [ -n "$token" ]; then
    composer config --global --auth github-oauth.github.com "$token"
    echo "composer: authenticated to github.com"
else
    echo "composer: no GITHUB_TOKEN, downloading anonymously (rate limits are per-IP)" >&2
fi

attempt=1
while [ "$attempt" -le "$ATTEMPTS" ]; do
    if [ "$attempt" -lt "$ATTEMPTS" ]; then
        source_flag=--prefer-dist
    else
        source_flag=--prefer-source
    fi

    # Fewer connections in flight on each retry: the 400s cluster under burst
    # concurrency, so trading speed for calm is exactly the right trade once
    # the fast path has already failed.
    COMPOSER_MAX_PARALLEL_HTTP=$((attempt >= 3 ? 2 : 6))
    export COMPOSER_MAX_PARALLEL_HTTP

    echo "composer install: attempt ${attempt}/${ATTEMPTS} (${source_flag}, ${COMPOSER_MAX_PARALLEL_HTTP} parallel)"

    if composer install \
        --no-dev \
        "$source_flag" \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader
    then
        exit 0
    fi

    if [ "$attempt" -lt "$ATTEMPTS" ]; then
        backoff=$((attempt * 20))
        echo "composer install failed; retrying in ${backoff}s" >&2
        sleep "$backoff"
    fi

    attempt=$((attempt + 1))
done

echo "composer install failed after ${ATTEMPTS} attempts" >&2
exit 1
