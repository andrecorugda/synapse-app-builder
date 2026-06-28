<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\AppBuilderService;
use Andre\AiPageBuilder\Ai\AppContextBuilder;
use Andre\AiPageBuilder\Ai\BuildPlanValidator;
use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;

/** A fake invoker returning a canned model reply — no real model call. */
function pbFakeInvoker(string $reply): AiInvoker
{
    return new class($reply) implements AiInvoker
    {
        public function __construct(private string $reply) {}

        public function available(): bool
        {
            return true;
        }

        public function invoke(string $integration, array $args = [], array $messages = [], array $opts = []): string
        {
            return $this->reply;
        }
    };
}

function pbAppBuilder(string $reply): AppBuilderService
{
    return new AppBuilderService(pbFakeInvoker($reply), app(AppContextBuilder::class), app(BuildPlanValidator::class));
}

it('parses a JSON build plan from the model reply and validates it clean', function (): void {
    $json = json_encode([
        'collections' => [[
            'key' => 'leads', 'name' => 'Leads',
            'fields' => [['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]]],
        ]],
        'states' => [['key' => 'count', 'type' => 'number', 'value' => 0]],
    ]);

    $res = pbAppBuilder($json)->generate('a simple leads app');

    expect($res['errors'])->toBe([])
        ->and($res['plan']['collections'][0]['key'])->toBe('leads')
        ->and($res['plan']['states'][0]['key'])->toBe('count');
});

it('tolerates code fences and surrounding prose around the JSON', function (): void {
    $reply = "Sure! Here's the plan:\n\n```json\n".json_encode(['states' => [['key' => 'greeting', 'type' => 'string', 'value' => 'hi']]])."\n```\nHope that helps.";

    $res = pbAppBuilder($reply)->generate('x');

    expect($res['plan']['states'][0]['key'])->toBe('greeting')
        ->and($res['errors'])->toBe([]);
});

it('surfaces validation errors for a malformed plan', function (): void {
    $json = json_encode([
        'collections' => [['key' => 'Bad Key!', 'name' => 'x', 'fields' => [['key' => 'f', 'label' => 'F', 'type' => 'not-a-type']]]],
    ]);

    $res = pbAppBuilder($json)->generate('x');

    expect($res['errors'])->not->toBe([]);
});

it('returns an empty plan (not a crash) when the reply has no JSON', function (): void {
    $res = pbAppBuilder('I cannot help with that.')->generate('x');

    expect($res['plan'])->toBe([]);
});
