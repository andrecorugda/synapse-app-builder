<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Exceptions;

use RuntimeException;

/**
 * Thrown inside a transaction body to force a rollback when the body soft-fails
 * (sets FlowContext::$failed) without itself throwing. Caught by TransactionNode.
 */
class FlowBodyFailed extends RuntimeException {}
