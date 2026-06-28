<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\FlowContext;
use Illuminate\Support\Facades\Http;

/**
 * Calls an external HTTP endpoint and stores the JSON/body in a var.
 * config: { method, url, headers:{...}, body:{...}, output:"varName" }
 */
class HttpRequestNode implements FlowNodeHandler
{
    public function type(): string
    {
        return 'http_request';
    }

    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);
        $method = strtolower((string) ($config['method'] ?? 'get'));
        $url = $context->interpolate((string) ($config['url'] ?? ''));
        /** @var array<string,mixed> $headers */
        $headers = $context->interpolateDeep((array) ($config['headers'] ?? []));
        /** @var array<string,mixed> $body */
        $body = $context->interpolateDeep((array) ($config['body'] ?? []));
        $output = (string) ($config['output'] ?? 'http');

        if ($url === '') {
            $context->set($output, null);

            return (array) ($node['next'] ?? []);
        }

        $response = Http::withHeaders($headers)->{$method}($url, $body);

        $context->set($output, $response->json() ?? $response->body());
        $context->set($output.'_status', $response->status());

        return (array) ($node['next'] ?? []);
    }
}
