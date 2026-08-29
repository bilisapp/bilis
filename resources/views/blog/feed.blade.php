<?php echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"; ?>
{{-- The Atom feed.

     Ids are the post URLs: a post's URL is its permanent name here — the
     slug comes from the filename and renaming a file is renaming the post —
     so there is nothing a `tag:` URI would buy that this does not.

     `updated` is the publication date rather than the file's modification
     time, so fixing a typo does not push the post back to the top of every
     subscriber's reader. --}}
<feed xmlns="http://www.w3.org/2005/Atom"
      xml:lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <title>{{ config('app.name', 'Bilis') }} blog</title>
    <subtitle>Notes on building Bilis — log storage, ClickHouse, OTLP, and the decisions behind a deliberately narrow
        product.
    </subtitle>
    <id>{{ route('blog.index') }}</id>
    <link rel="alternate"
          type="text/html"
          href="{{ route('blog.index') }}" />
    <link rel="self"
          type="application/atom+xml"
          href="{{ route('blog.feed') }}" />
    <updated>{{ $updated->toAtomString() }}</updated>
    <author>
        <name>{{ config('app.name', 'Bilis') }}</name>
    </author>
    @foreach ($posts as $post)
        <entry>
            <title>{{ $post->title }}</title>
            <id>{{ $post->url() }}</id>
            <link rel="alternate"
                  type="text/html"
                  href="{{ $post->url() }}" />
            <published>{{ $post->date->toAtomString() }}</published>
            <updated>{{ $post->date->toAtomString() }}</updated>
            <author>
                <name>{{ $post->author ?? config('app.name', 'Bilis') }}</name>
            </author>
            @if ($post->description)
                <summary type="text">{{ $post->description }}</summary>
            @endif
            <content type="html">{{ $entries[$loop->index] }}</content>
        </entry>
    @endforeach
</feed>
