<?php

namespace App\Services\Tools;

use RuntimeException;

/**
 * A page the crawler view could not fetch, with a sentence for the visitor.
 */
final class PageFetchFailed extends RuntimeException {}
