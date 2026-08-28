<?php

namespace App\Services\Autofix;

use RuntimeException;

/**
 * A diff that parses but does not apply to the content it was handed.
 *
 * The usual cause is the default branch moving on past `base_sha` while the
 * agent worked, which is why the validator answers this with one re-dispatch
 * before it gives up.
 */
class DiffApplyException extends RuntimeException {}
