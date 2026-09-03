<?php

/*
 * The public MCP page. It is the surface a reader lands on before deciding to
 * paste a command that gives an agent access to their telemetry, so what it
 * promises is pinned here.
 */

it('renders the connect command and the read-only boundary', function () {
    $page = html($this->get(route('features.mcp'))->assertOk());

    expect($page)
        ->toContain('claude mcp add --transport http bilis')
        ->toContain('read-only')
        ->toContain('error-summary')
        ->toContain('get-trace');
});

it('links to the MCP guide rather than restating it', function () {
    expect(html($this->get(route('features.mcp'))->assertOk()))
        ->toContain(route('docs.show', ['section' => 'reference', 'page' => 'mcp']));
});

it('wears the shared public chrome', function () {
    $page = html($this->get(route('features.mcp'))->assertOk());

    // One header, one footer, everywhere: the nav marks Features as current.
    expect(substr_count($page, 'aria-current="page"'))->toBe(2);
    expect($page)->toContain(route('docs.index'));
});
