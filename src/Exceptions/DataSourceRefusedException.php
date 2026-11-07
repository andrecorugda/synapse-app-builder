<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A collection points at a table it may not use (Synapse's own tables, the
 * host's auth tables, or an external table the host did not list), or tries
 * to write an external table that is read-only. Renders as HTTP 403.
 */
final class DataSourceRefusedException extends AccessDeniedHttpException {}
