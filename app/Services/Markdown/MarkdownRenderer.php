<?php

namespace App\Services\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Turns a markdown body into HTML and the list of its own headings.
 *
 * Shared by the documentation and the blog: both are markdown files that
 * ship with the application and are rendered into `.docs-prose`, so both
 * want the same anchors, the same GitHub-flavoured tables and code fences,
 * and the same heading list. What each surface *does* with that list is its
 * own business — the docs render an "On this page" rail from it, a blog post
 * shows one only when it is long enough to earn it.
 *
 * Raw HTML is stripped rather than passed through: the content is ours, but
 * it has no reason to carry markup the design system cannot style.
 */
class MarkdownRenderer
{
    /**
     * The heading levels collected into the returned heading list.
     *
     * @var array<int, int>
     */
    private const TOC_LEVELS = [2, 3];

    private readonly MarkdownParser $parser;

    private readonly HtmlRenderer $renderer;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'html_class' => 'docs-anchor',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'after',
                'title' => 'Permalink to this section',
                'symbol' => '#',
                'aria_hidden' => true,
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        $this->parser = new MarkdownParser($environment);
        $this->renderer = new HtmlRenderer($environment);
    }

    /**
     * Render a markdown body.
     */
    public function render(string $markdown): RenderedMarkdown
    {
        $document = $this->parser->parse($markdown);

        return new RenderedMarkdown(
            html: (string) $this->renderer->renderDocument($document),
            tableOfContents: $this->tableOfContents($document),
        );
    }

    /**
     * Collect the h2/h3 headings, using the anchors the permalink extension
     * already generated so the links can never drift from the ids.
     *
     * @return array<int, array{id: string, title: string, level: int}>
     */
    private function tableOfContents(Document $document): array
    {
        $entries = [];
        $walker = $document->walker();

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if (! $event->isEntering() || ! $node instanceof Heading) {
                continue;
            }

            if (! in_array($node->getLevel(), self::TOC_LEVELS, true)) {
                continue;
            }

            $anchor = $this->anchor($node);
            $title = trim($this->text($node));

            if ($anchor === null || $title === '') {
                continue;
            }

            $entries[] = ['id' => $anchor, 'title' => $title, 'level' => $node->getLevel()];
        }

        return $entries;
    }

    /**
     * The slug the permalink extension attached to a heading, if any.
     */
    private function anchor(Node $heading): ?string
    {
        foreach ($heading->children() as $child) {
            if ($child instanceof HeadingPermalink) {
                return $child->getSlug();
            }
        }

        return null;
    }

    /**
     * The plain text of a heading, ignoring its permalink anchor.
     */
    private function text(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            if ($child instanceof HeadingPermalink) {
                continue;
            }

            $text .= $child instanceof StringContainerInterface
                ? $child->getLiteral()
                : $this->text($child);
        }

        return $text;
    }
}
