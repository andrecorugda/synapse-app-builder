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
     * @param  array<string,mixed>  $args  values for the integration prompt's {{placeholders}}
     * @param  array<int,array<string,mixed>>  $messages  conversation messages (role/content) for threaded calls
     * @param  array<string,mixed>  $opts  per-call gateway options
     */
    public function invoke(string $integration, array $args = [], array $messages = [], array $opts = []): string;
}
