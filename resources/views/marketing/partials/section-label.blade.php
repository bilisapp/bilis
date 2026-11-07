{{-- The page's spine: every section below the hero is numbered in the mono
     face the log data itself is set in. A thin wrapper over the shared
     eyebrow so older pages and the landing page speak the same way.

     Expects $number and $label from the @include. --}}
<x-ui.eyebrow :number="$number">{{ $label }}</x-ui.eyebrow>
