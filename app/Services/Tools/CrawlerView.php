<?php

namespace App\Services\Tools;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * What a crawler that does not run JavaScript reads on a page.
 *
 * Built from the raw HTML only: the head a search result is made from, the
 * text in reading order, the links a crawler can follow, and the signs that
 * the content arrives later from a script (an empty `#app`, a `<noscript>`
 * plea, next to no words). Nothing here executes the page.
 */
final readonly class CrawlerView
{
    /** Elements whose content no crawler reads as page text. */
    private const SKIPPED = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'canvas', 'head', 'math'];

    /** Elements read as one block of text each. */
    private const BLOCKS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'dt', 'dd', 'blockquote', 'pre', 'figcaption', 'caption', 'th', 'td', 'summary', 'button', 'label', 'option', 'legend'];

    /** Elements that are only text runs inside a block. */
    private const INLINE = ['a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr', 'br', 'font'];

    /** Ids a single-page app usually mounts into. */
    private const MOUNTS = ['root', 'app', '__next', '__nuxt', 'svelte', '___gatsby', 'main-app', 'application'];

    public const MAX_BLOCKS = 250;

    /**
     * @param  list<array{level: int, text: string}>  $headings
     * @param  list<string>  $structuredDataTypes
     * @param  list<array{tag: string, text: string}>  $blocks
     * @param  list<string>  $frameworks
     * @param  array{internal: int, external: int, nofollow: int, uncrawlable: int}  $links
     * @param  array{total: int, missingAlt: int}  $images
     * @param  array{external: int, inline: int, inlineBytes: int}  $scripts
     */
    private function __construct(
        public FetchedPage $page,
        public string $title,
        public string $description,
        public string $canonical,
        public string $robots,
        public string $lang,
        public string $ogTitle,
        public string $ogDescription,
        public string $ogImage,
        public array $headings,
        public array $structuredDataTypes,
        public array $blocks,
        public int $words,
        public bool $blocksTruncated,
        public array $links,
        public array $images,
        public array $scripts,
        public array $frameworks,
        public bool $emptyMount,
        public string $noscript,
    ) {}

    public static function from(FetchedPage $page): self
    {
        $document = HTMLDocument::createFromString($page->html === '' ? '<!doctype html><html></html>' : $page->html, LIBXML_NOERROR);

        $blocks = [];
        $pending = '';

        if ($document->body !== null) {
            self::walk($document->body, $blocks, $pending);
        }

        self::flush($blocks, $pending);

        $words = array_sum(array_map(
            fn (array $block): int => (int) preg_match_all('/[\p{L}\p{N}]+/u', $block['text']),
            array_filter($blocks, fn (array $block): bool => $block['tag'] !== 'img'),
        ));

        return new self(
            page: $page,
            title: self::clean($document->title),
            description: self::meta($document, 'meta[name="description" i]'),
            canonical: self::attribute($document, 'link[rel~="canonical" i]', 'href'),
            robots: self::meta($document, 'meta[name="robots" i]'),
            lang: self::attribute($document, 'html', 'lang'),
            ogTitle: self::meta($document, 'meta[property="og:title" i]'),
            ogDescription: self::meta($document, 'meta[property="og:description" i]'),
            ogImage: self::meta($document, 'meta[property="og:image" i]'),
            headings: self::headings($document),
            structuredDataTypes: self::structuredDataTypes($document),
            blocks: array_slice($blocks, 0, self::MAX_BLOCKS),
            words: $words,
            blocksTruncated: count($blocks) > self::MAX_BLOCKS,
            links: self::links($document, $page->finalUrl),
            images: self::images($document),
            scripts: self::scripts($document),
            frameworks: self::frameworks($document, $page->html),
            emptyMount: self::hasEmptyMount($document),
            noscript: self::clean(implode(' ', array_map(
                fn (Element $element): string => strip_tags(html_entity_decode($element->textContent ?? '')),
                iterator_to_array($document->querySelectorAll('body noscript')),
            ))),
        );
    }

    /**
     * How much of the page is there before JavaScript runs.
     *
     * `client` — next to nothing until a script runs; `thin` — some text, but
     * little enough that the real content is probably rendered later; `server`
     * — the content is in the HTML.
     *
     * @return 'server'|'thin'|'client'
     */
    public function verdict(): string
    {
        // A short page is not a rendered one: calling it client-side takes
        // evidence of an app shell (an empty mount, a <noscript> plea).
        if ($this->words < 50 && ($this->emptyMount || $this->noscript !== '')) {
            return 'client';
        }

        // Inline scripts are mostly analytics and config; a bundle that
        // renders the page is loaded from a file.
        if ($this->words < 200 && ($this->emptyMount || $this->scripts['external'] > 0)) {
            return 'thin';
        }

        return 'server';
    }

    public function isNoindex(): bool
    {
        return str_contains(strtolower($this->robots.' '.$this->page->robotsHeader), 'noindex');
    }

    /**
     * What a crawler would trip over, worst first.
     *
     * @return list<array{level: 'error'|'warn', text: string}>
     */
    public function findings(): array
    {
        $findings = [];
        $h1s = count(array_filter($this->headings, fn (array $heading): bool => $heading['level'] === 1));

        if ($this->page->status >= 400) {
            $findings[] = ['level' => 'error', 'text' => "The server answered {$this->page->status}. A crawler treats the page as missing, whatever is in the body."];
        }

        if ($this->isNoindex()) {
            $findings[] = ['level' => 'error', 'text' => 'The page asks not to be indexed (noindex in the robots meta tag or the X-Robots-Tag header).'];
        }

        if ($this->verdict() === 'client') {
            $findings[] = ['level' => 'error', 'text' => 'Almost no text arrives without JavaScript. Google may render it later; most AI crawlers and link previews never will.'];
        }

        if ($this->title === '') {
            $findings[] = ['level' => 'error', 'text' => 'There is no <title> in the HTML.'];
        } elseif (mb_strlen($this->title) > 65) {
            $findings[] = ['level' => 'warn', 'text' => 'The title is '.mb_strlen($this->title).' characters; around 60 is what a result shows before cutting it.'];
        }

        if ($this->description === '') {
            $findings[] = ['level' => 'warn', 'text' => 'There is no meta description, so a search engine picks a snippet itself.'];
        }

        if ($h1s === 0) {
            $findings[] = ['level' => 'warn', 'text' => 'No <h1> in the HTML.'];
        } elseif ($h1s > 1) {
            $findings[] = ['level' => 'warn', 'text' => "{$h1s} <h1> elements; one says more clearly what the page is about."];
        }

        if ($this->canonical === '') {
            $findings[] = ['level' => 'warn', 'text' => 'No canonical link, so every query-string variant of this URL competes with the others.'];
        }

        if ($this->lang === '') {
            $findings[] = ['level' => 'warn', 'text' => 'The <html> element has no lang attribute.'];
        }

        if ($this->links['uncrawlable'] > 0) {
            $findings[] = ['level' => 'warn', 'text' => "{$this->links['uncrawlable']} links have no real href (empty, # or javascript:), so a crawler cannot follow them."];
        }

        if ($this->images['missingAlt'] > 0) {
            $findings[] = ['level' => 'warn', 'text' => "{$this->images['missingAlt']} of {$this->images['total']} images have no alt attribute."];
        }

        if ($this->ogTitle === '' && $this->ogImage === '') {
            $findings[] = ['level' => 'warn', 'text' => 'No Open Graph tags, so a shared link previews without a card.'];
        }

        if ($this->page->truncated) {
            $findings[] = ['level' => 'warn', 'text' => 'The page is over '.number_format(PageFetcher::MAX_BYTES / 1_000_000).' MB of HTML; only the first part was read.'];
        }

        return $findings;
    }

    /**
     * @param  list<array{tag: string, text: string}>  $blocks
     */
    private static function walk(Node $parent, array &$blocks, string &$pending): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof Text) {
                $pending .= $node->data;

                continue;
            }

            if (! $node instanceof Element) {
                continue;
            }

            $tag = strtolower($node->localName);

            if (in_array($tag, self::SKIPPED, true)) {
                continue;
            }

            if ($tag === 'img') {
                self::flush($blocks, $pending);
                $blocks[] = ['tag' => 'img', 'text' => self::clean($node->getAttribute('alt') ?? '')];

                continue;
            }

            if (in_array($tag, self::INLINE, true)) {
                $pending .= $tag === 'br' ? ' ' : '';
                self::walk($node, $blocks, $pending);

                continue;
            }

            self::flush($blocks, $pending);

            if (in_array($tag, self::BLOCKS, true)) {
                $text = self::clean(self::visibleText($node));

                if ($text !== '') {
                    $blocks[] = ['tag' => $tag, 'text' => mb_substr($text, 0, 600)];
                }

                continue;
            }

            self::walk($node, $blocks, $pending);
            self::flush($blocks, $pending);
        }
    }

    /**
     * @param  list<array{tag: string, text: string}>  $blocks
     */
    private static function flush(array &$blocks, string &$pending): void
    {
        $text = self::clean($pending);
        $pending = '';

        if ($text !== '') {
            $blocks[] = ['tag' => 'text', 'text' => mb_substr($text, 0, 600)];
        }
    }

    private static function visibleText(Node $parent): string
    {
        $text = '';

        foreach ($parent->childNodes as $node) {
            if ($node instanceof Text) {
                $text .= $node->data;
            } elseif ($node instanceof Element && ! in_array(strtolower($node->localName), self::SKIPPED, true)) {
                $text .= ' '.self::visibleText($node).' ';
            }
        }

        return $text;
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function meta(HTMLDocument $document, string $selector): string
    {
        return self::attribute($document, $selector, 'content');
    }

    private static function attribute(HTMLDocument $document, string $selector, string $name): string
    {
        return self::clean($document->querySelector($selector)?->getAttribute($name) ?? '');
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    private static function headings(HTMLDocument $document): array
    {
        $headings = [];

        foreach ($document->querySelectorAll('body h1, body h2, body h3, body h4, body h5, body h6') as $element) {
            $headings[] = [
                'level' => (int) substr(strtolower($element->localName), 1),
                'text' => mb_substr(self::clean(self::visibleText($element)), 0, 200),
            ];
        }

        return array_slice($headings, 0, 60);
    }

    /**
     * @return list<string>
     */
    private static function structuredDataTypes(HTMLDocument $document): array
    {
        $types = [];

        foreach ($document->querySelectorAll('script[type="application/ld+json" i]') as $script) {
            $data = json_decode($script->textContent ?? '', true);

            if (! is_array($data)) {
                continue;
            }

            $nodes = array_is_list($data) ? $data : [$data];

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                foreach (is_array($node['@graph'] ?? null) ? $node['@graph'] : [$node] as $item) {
                    foreach ((array) (is_array($item) ? ($item['@type'] ?? []) : []) as $type) {
                        if (is_string($type)) {
                            $types[] = $type;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array{internal: int, external: int, nofollow: int, uncrawlable: int}
     */
    private static function links(HTMLDocument $document, string $base): array
    {
        $host = self::bareHost((string) parse_url($base, PHP_URL_HOST));
        $counts = ['internal' => 0, 'external' => 0, 'nofollow' => 0, 'uncrawlable' => 0];

        foreach ($document->querySelectorAll('body a') as $link) {
            $href = trim($link->getAttribute('href') ?? '');

            if ($href === '' || $href === '#' || str_starts_with(strtolower($href), 'javascript:')) {
                $counts['uncrawlable']++;

                continue;
            }

            if (preg_match('/^(mailto|tel|sms|data):/i', $href) === 1 || str_starts_with($href, '#')) {
                continue;
            }

            $linkHost = parse_url($href, PHP_URL_HOST);
            $counts[! is_string($linkHost) || self::bareHost($linkHost) === $host ? 'internal' : 'external']++;

            if (str_contains(strtolower($link->getAttribute('rel') ?? ''), 'nofollow')) {
                $counts['nofollow']++;
            }
        }

        return $counts;
    }

    private static function bareHost(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * @return array{total: int, missingAlt: int}
     */
    private static function images(HTMLDocument $document): array
    {
        $images = $document->querySelectorAll('body img');

        return [
            'total' => $images->length,
            'missingAlt' => count(array_filter(
                iterator_to_array($images),
                fn (Element $image): bool => ! $image->hasAttribute('alt'),
            )),
        ];
    }

    /**
     * @return array{external: int, inline: int, inlineBytes: int}
     */
    private static function scripts(HTMLDocument $document): array
    {
        $counts = ['external' => 0, 'inline' => 0, 'inlineBytes' => 0];

        foreach ($document->querySelectorAll('script') as $script) {
            $type = strtolower($script->getAttribute('type') ?? '');

            if ($type !== '' && ! in_array($type, ['module', 'text/javascript', 'application/javascript'], true)) {
                continue;
            }

            if ($script->hasAttribute('src')) {
                $counts['external']++;
            } else {
                $counts['inline']++;
                $counts['inlineBytes'] += strlen($script->textContent ?? '');
            }
        }

        return $counts;
    }

    /**
     * Front-end frameworks the markup gives away, by their own fingerprints.
     *
     * @return list<string>
     */
    private static function frameworks(HTMLDocument $document, string $html): array
    {
        $signals = [
            'Next.js' => $document->querySelector('#__next, script#__NEXT_DATA__') !== null || str_contains($html, 'self.__next_f'),
            'Nuxt' => $document->querySelector('#__nuxt') !== null || str_contains($html, 'window.__NUXT__'),
            'Gatsby' => $document->querySelector('#___gatsby') !== null,
            'Angular' => $document->querySelector('[ng-version]') !== null,
            'SvelteKit' => str_contains($html, '__sveltekit') || $document->querySelector('[data-svelte-h]') !== null,
            'Astro' => $document->querySelector('astro-island') !== null,
            'Inertia' => $document->querySelector('#app[data-page], script[data-page]') !== null,
            'React' => $document->querySelector('[data-reactroot]') !== null,
            'Vue' => $document->querySelector('[data-v-app], [data-server-rendered]') !== null || preg_match('/\sdata-v-[0-9a-f]{8}/', $html) === 1,
            'WordPress' => str_contains($html, '/wp-content/') || str_contains($html, '/wp-includes/'),
        ];

        return array_keys(array_filter($signals));
    }

    private static function hasEmptyMount(HTMLDocument $document): bool
    {
        $selector = implode(', ', array_map(fn (string $id): string => "body #{$id}", self::MOUNTS));

        foreach ($document->querySelectorAll($selector) as $mount) {
            if (self::clean(self::visibleText($mount)) === '') {
                return true;
            }
        }

        return false;
    }
}
