{{-- The OAuth consent screen an AI client sends someone to.

     It is a public surface — the first Bilis page a stranger's agent opens —
     so it wears the same chrome as the marketing pages rather than the
     vendor's standalone card. Achromatic throughout: nothing on this page is
     data, so nothing on it carries hue, and the primary action is the
     foreground/background pair the rest of the app uses.

     Passport hands us `$client`, `$user`, `$scopes`, `$request` and
     `$authToken`. Both forms post `auth_token`, `state` and `client_id`; deny
     is the same endpoint with DELETE. --}}
<x-layouts.marketing title="Authorize {{ $client->name }}"
                     description="Approve an AI client's request to read this Bilis account's logs and traces.">
    <section class="mx-auto flex w-full max-w-xl flex-col px-6 py-16 sm:py-24">
        <p class="font-mono text-xs tracking-wide text-muted-foreground uppercase">
            Connection request
        </p>

        <h1 class="mt-3 text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
            {{ $client->name }} wants to read your logs and traces
        </h1>

        <p class="mt-4 leading-relaxed text-muted-foreground">
            It asked Bilis for access as
            <span class="font-medium text-foreground">{{ $user->email }}</span>.
            Approve it only if you started this from an assistant you trust —
            the name above is chosen by the client, not verified by us.
        </p>

        {{-- What the token can and cannot do. The boundary is the whole reason
             a person can approve this quickly, so it is stated, not implied. --}}
        <dl class="mt-8 divide-y divide-border border-y border-border">
            <div class="grid gap-1 py-4 sm:grid-cols-[10rem_1fr] sm:gap-6">
                <dt class="text-sm font-medium">It will be able to</dt>
                <dd class="text-sm text-muted-foreground">
                    Read the logs and traces of every project in the teams you belong to,
                    and list those teams, projects and services.
                    @if (count($scopes) > 0)
                        <span class="mt-2 block font-mono text-xs text-muted-foreground/80">
                            @foreach ($scopes as $scope)
                                {{ $scope->description }}@if (! $loop->last), @endif
                            @endforeach
                        </span>
                    @endif
                </dd>
            </div>

            <div class="grid gap-1 py-4 sm:grid-cols-[10rem_1fr] sm:gap-6">
                <dt class="text-sm font-medium">It will not be able to</dt>
                <dd class="text-sm text-muted-foreground">
                    Send or delete anything, create a project, read or issue an API key,
                    change a setting, or start an Autofix job. The connection is read-only.
                </dd>
            </div>

            <div class="grid gap-1 py-4 sm:grid-cols-[10rem_1fr] sm:gap-6">
                <dt class="text-sm font-medium">For how long</dt>
                <dd class="text-sm text-muted-foreground">
                    Access expires after 24 hours and is renewed silently for up to 30 days
                    while the client keeps using it. Sign out of the client to end it sooner.
                </dd>
            </div>
        </dl>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row-reverse">
            <form method="POST"
                  action="{{ route('passport.authorizations.approve') }}"
                  class="sm:flex-1">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">

                <button type="submit"
                        class="inline-flex h-11 w-full items-center justify-center rounded-md bg-foreground px-6 text-sm font-medium text-background transition-colors hover:bg-foreground/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none">
                    Approve
                </button>
            </form>

            <form method="POST"
                  action="{{ route('passport.authorizations.deny') }}"
                  class="sm:flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">

                <button type="submit"
                        class="inline-flex h-11 w-full items-center justify-center rounded-md border border-border bg-background px-6 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none">
                    Cancel
                </button>
            </form>
        </div>

        <p class="mt-6 text-sm text-muted-foreground">
            Not sure what this is?
            <a href="{{ route('docs.show', ['section' => 'reference', 'page' => 'mcp']) }}"
               class="font-medium text-foreground underline underline-offset-4">Read about the MCP server</a>
            before approving.
        </p>
    </section>
</x-layouts.marketing>
