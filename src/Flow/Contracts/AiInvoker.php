<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Contracts;

/**
 * Abstraction over AI invocation so the flow engine doesn't hard-depend on the
 * AI OpenRouter Gateway. The default implementation routes through the gateway
 * when installed; tests bind a fake.
 */
interface AiInvoker
{
    public function available(): bool;

    /**
     * @param  array<string,mixed>  $args
     */
    public function invoke(string $integration, array $args = []): string;
}
