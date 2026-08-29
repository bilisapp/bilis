<?php

declare(strict_types=1);

use App\Services\Blog\BlogRepository;
use App\Services\Markdown\MarkdownRenderer;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\get;

/**
 * Point the container's blog repository at a scratch directory and write the
 * given `{filename => contents}` posts into it.
 *
 * @param  array<string, string>  $files
 */
function blogFixture(array $files): string
{
    $directory = base_path('tests/tmp/blog-'.uniqid());

    File::ensureDirectoryExists($directory);

    foreach ($files as $name => $contents) {
        File::put($directory.'/'.$name, $contents);
    }

    app()->instance(BlogRepository::class, new BlogRepository(app(MarkdownRenderer::class), $directory));

    return $directory;
}

afterEach(function () {
    File::deleteDirectory(base_path('tests/tmp'));
});

it('lists the posts newest first', function () {
    blogFixture([
        '2026-01-02-older.md' => "---\ntitle: The older post\ndate: 2026-01-02\n---\n\nOld.\n",
        '2026-03-04-newer.md' => "---\ntitle: The newer post\ndate: 2026-03-04\n---\n\nNew.\n",
    ]);

    $titles = array_map(fn ($post) => $post->title, app(BlogRepository::class)->posts());

    expect($titles)->toBe(['The newer post', 'The older post']);

    $body = get(route('blog.index'))->assertOk()->getContent();

    expect(strpos($body, 'The newer post'))->toBeLessThan(strpos($body, 'The older post'));
});

it('renders the real posts on the index, newest first', function () {
    $posts = app(BlogRepository::class)->posts();

    expect($posts)->not->toBeEmpty();

    $dates = array_map(fn ($post) => $post->date->timestamp, $posts);
    $sorted = $dates;
    rsort($sorted);

    expect($dates)->toBe($sorted);

    get(route('blog.index'))
        ->assertOk()
        ->assertSee('Notes on building Bilis')
        ->assertSee($posts[0]->title)
        ->assertSee($posts[0]->url(), false);
});

it('renders a post from its markdown', function () {
    blogFixture([
        '2026-05-06-a-post.md' => <<<'MD'
            ---
            title: A post about ingest
            description: What the endpoint answers.
            date: 2026-05-06
            author: Samuel Vrablik
            ---

            ## The never-400 contract

            Ingest answers `202` with a count.

            ```json
            { "accepted": 997, "skipped": 3 }
            ```
            MD,
    ]);

    get(route('blog.show', ['post' => 'a-post']))
        ->assertOk()
        ->assertSee('A post about ingest')
        ->assertSee('<h2>', false)
        ->assertSee('The never-400 contract')
        ->assertSee('id="the-never-400-contract"', false)
        ->assertSee('<pre><code class="language-json">', false)
        ->assertSee('docs-prose', false);
});

it('drives the page title, description and social tags from the front matter', function () {
    blogFixture([
        '2026-05-06-a-post.md' => "---\ntitle: A post about ingest\ndescription: What the endpoint answers.\ndate: 2026-05-06\nauthor: Samuel Vrablik\n---\n\nBody.\n",
    ]);

    $response = get(route('blog.show', ['post' => 'a-post']))
        ->assertOk()
        ->assertSee('Samuel Vrablik')
        ->assertSee('6 May 2026');

    expect(html($response))
        ->toContain('<title>A post about ingest — '.config('app.name').'</title>')
        ->toContain('<meta name="description" content="What the endpoint answers.">')
        ->toContain('<meta property="og:title" content="A post about ingest — '.config('app.name').'">');
});

it('offers the feed for autodiscovery on the blog and nowhere else', function () {
    $tag = 'rel="alternate" type="application/atom+xml"';

    expect(html(get(route('blog.index'))->assertOk()))
        ->toContain($tag)
        ->toContain(route('blog.feed'));

    expect(html(get(route('home'))->assertOk()))->not->toContain($tag);
});

it('links the older and newer post from a post', function () {
    blogFixture([
        '2026-01-02-older.md' => "---\ntitle: The older post\ndate: 2026-01-02\n---\n\nOld.\n",
        '2026-02-03-middle.md' => "---\ntitle: The middle post\ndate: 2026-02-03\n---\n\nMid.\n",
        '2026-03-04-newer.md' => "---\ntitle: The newer post\ndate: 2026-03-04\n---\n\nNew.\n",
    ]);

    get(route('blog.show', ['post' => 'middle']))
        ->assertOk()
        ->assertSee('Older')
        ->assertSee('Newer')
        ->assertSee(route('blog.show', ['post' => 'older']), false)
        ->assertSee(route('blog.show', ['post' => 'newer']), false);

    // The newest post has nothing newer than it.
    get(route('blog.show', ['post' => 'newer']))->assertOk()->assertDontSee('>Newer<', false);
});

it('shows a table of contents only when the post is long enough to need one', function () {
    $short = "---\ntitle: Short\ndate: 2026-01-01\n---\n\n## One\n\nText.\n\n## Two\n\nText.\n";
    $long = $short;

    foreach (['Three', 'Four', 'Five'] as $heading) {
        $long .= "\n## {$heading}\n\nText.\n";
    }

    blogFixture(['2026-01-01-short.md' => $short, '2026-01-02-long.md' => $long]);

    get(route('blog.show', ['post' => 'short']))->assertOk()->assertDontSee('On this page');
    get(route('blog.show', ['post' => 'long']))->assertOk()->assertSee('On this page')->assertSee('#five', false);
});

it('404s for an unknown post', function () {
    get('/blog/no-such-post')->assertNotFound();
});

it('keeps a draft off the index, out of the feed and behind a 404', function () {
    blogFixture([
        '2026-01-02-published.md' => "---\ntitle: The published post\ndate: 2026-01-02\n---\n\nOut.\n",
        '2026-03-04-in-progress.md' => "---\ntitle: The unfinished post\ndate: 2026-03-04\ndraft: true\n---\n\nNot yet.\n",
    ]);

    expect(app(BlogRepository::class)->find('in-progress'))->toBeNull();

    get(route('blog.index'))->assertOk()->assertSee('The published post')->assertDontSee('The unfinished post');
    get(route('blog.feed'))->assertOk()->assertDontSee('The unfinished post');
    get(route('blog.show', ['post' => 'in-progress']))->assertNotFound();
});

it('serves a well-formed atom feed', function () {
    blogFixture([
        '2026-05-06-a-post.md' => "---\ntitle: A post about ingest\ndescription: What the endpoint answers.\ndate: 2026-05-06\nauthor: Samuel Vrablik\n---\n\n## A heading\n\nBody with a <tag> & an ampersand.\n",
    ]);

    $response = get(route('blog.feed'))->assertOk();

    $response->assertHeader('Content-Type', 'application/atom+xml; charset=utf-8');

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string) $response->getContent());
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($errors)->toBe([])
        ->and($xml)->not->toBeFalse();

    /** @var SimpleXMLElement $xml */
    expect((string) $xml->title)->toBe(config('app.name').' blog')
        ->and((string) $xml->id)->toBe(route('blog.index'))
        ->and((string) $xml->updated)->not->toBe('');

    $self = null;
    $alternate = null;

    foreach ($xml->link as $link) {
        match ((string) $link['rel']) {
            'self' => $self = (string) $link['href'],
            'alternate' => $alternate = (string) $link['href'],
            default => null,
        };
    }

    expect($self)->toBe(route('blog.feed'))
        ->and($alternate)->toBe(route('blog.index'));

    expect($xml->entry)->toHaveCount(1);

    $entry = $xml->entry[0];

    expect((string) $entry->title)->toBe('A post about ingest')
        ->and((string) $entry->id)->toBe(route('blog.show', ['post' => 'a-post']))
        ->and((string) $entry->author->name)->toBe('Samuel Vrablik')
        ->and((string) $entry->summary)->toBe('What the endpoint answers.')
        ->and((string) $entry->published)->toBe('2026-05-06T00:00:00+00:00')
        ->and((string) $entry->updated)->toBe('2026-05-06T00:00:00+00:00');

    // The content is escaped HTML, and survives the round trip as markup.
    expect((string) $entry->content['type'])->toBe('html')
        ->and((string) $entry->content)->toContain('<h2>')
        ->and((string) $entry->content)->toContain('&amp; an ampersand');
});

it('keeps the blog free of the inertia bundle', function () {
    $content = get(route('blog.index'))->getContent();

    expect($content)->not->toContain('data-page=')
        ->and($content)->not->toContain('resources/js/app.ts');
});
