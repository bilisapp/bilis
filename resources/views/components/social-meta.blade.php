@props([
    'title',
    'description',
])

{{--
  Open Graph / Twitter card, shared by every public Blade layout so the two
  cannot drift apart. It takes the page title and description the layout has
  already composed rather than inventing a second set of strings.
--}}
<meta property="og:type"
      content="website">
<meta property="og:site_name"
      content="{{ config('app.name', 'Bilis') }}">
<meta property="og:url"
      content="{{ url()->current() }}">
<meta property="og:title"
      content="{{ $title }}">
<meta property="og:description"
      content="{{ $description }}">
<meta property="og:image"
      content="{{ asset('og.png') }}">
<meta property="og:image:width"
      content="1200">
<meta property="og:image:height"
      content="630">
<meta property="og:image:alt"
      content="{{ config('app.name', 'Bilis') }} — your logs, on your own box.">

<meta name="twitter:card"
      content="summary_large_image">
<meta name="twitter:title"
      content="{{ $title }}">
<meta name="twitter:description"
      content="{{ $description }}">
<meta name="twitter:image"
      content="{{ asset('og.png') }}">
