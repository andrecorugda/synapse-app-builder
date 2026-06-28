<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;

/**
 * Default AiInvoker — routes through the AI OpenRouter Gateway when installed.
 * The gateway is optional, so its facade is referenced as a string literal
 * (never imported / ::class) and guarded by class_exists.
 */
class GatewayAiInvoker implements AiInvoker
{
    private const FACADE = 'Andre\\AiGateway\\Facades\\AiGateway';

    public function available(): bool
    {
        return class_exists(self::FACADE);
    }

    public function invoke(string $integration, array $args = []): string
    {
        if (! $this->available()) {
            throw new \RuntimeException('AI invocation requires the AI OpenRouter Gateway (andrecorugda/ai-openrouter-gateway).');
        }

        $facade = self::FACADE;
        $result = $facade::invoke($integration, $args);

        return (string) ($result->text ?? '');
    }
}
