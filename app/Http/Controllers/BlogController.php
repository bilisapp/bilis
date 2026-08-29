<?php

namespace App\Http\Controllers;

use App\Services\Blog\BlogPost;
use App\Services\Blog\BlogRepository;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public blog, rendered from `resources/blog/*.md`.
 */
class BlogController extends Controller
{
    /**
     * How many h2/h3 headings a post needs before it is worth a contents
     * list. Below this the list is longer than the scrolling it saves.
     */
    private const TOC_MINIMUM_HEADINGS = 4;

    public function __construct(private readonly BlogRepository $posts) {}

    /**
     * Every post, newest first.
     */
    public function index(): View
    {
        return view('blog.index', ['posts' => $this->posts->posts()]);
    }

    /**
     * The Atom feed.
     *
     * Entries carry the whole post, not a teaser: the blog is a handful of
     * essays a year, and a reader who subscribed asked to read them.
     */
    public function feed(): HttpResponse
    {
        $posts = $this->posts->posts();

        return response()
            ->view('blog.feed', [
                'posts' => $posts,
                'updated' => $this->posts->updatedAt(),
                'entries' => array_map(
                    fn (BlogPost $post): string => $this->posts->render($post)->html,
                    $posts,
                ),
            ])
            ->header('Content-Type', 'application/atom+xml; charset=utf-8');
    }

    /**
     * One post.
     */
    public function show(string $post): View
    {
        $found = $this->posts->find($post);

        if ($found === null) {
            throw new NotFoundHttpException;
        }

        $rendered = $this->posts->render($found);

        return view('blog.show', [
            'post' => $found,
            'rendered' => $rendered,
            'neighbours' => $this->posts->neighbours($found),
            'toc' => count($rendered->tableOfContents) >= self::TOC_MINIMUM_HEADINGS
                ? $rendered->tableOfContents
                : [],
        ]);
    }
}
