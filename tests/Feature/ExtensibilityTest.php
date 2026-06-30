<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Facades\PageBuilder;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\ExpressionEvaluator;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\NodeRegistry;
use Illuminate\Support\Str;

/**
 * Phase 5 — the public extensibility seam. A host app / third-party package
 * registers its own flow node + function helper via the PageBuilder facade and
 * gets it resolvable, callable, AND catalogued for the builder UI + MCP/AI — with
 * no core change.
 */

/** A minimal self-describing custom node used as the extensibility proof. */
class TestSlugifyNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    public function type(): string
    {
        return 'test_slugify';
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);
        $text = (string) $context->interpolateDeep((string) ($config['text'] ?? ''));
        $context->set((string) ($config['output'] ?? 'slug'), Str::slug($text));

        return (array) ($node['next'] ?? []);
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Test Slugify',
            category: CapabilityCategory::Util,
            description: 'Turns a string into a URL-safe slug.',
            usage: 'text "{{ input.title }}", output "slug"',
            inputs: [
                new CapabilityInput('text', 'Text', 'expression', required: true),
                new CapabilityInput('output', 'Context variable', 'string', default: 'slug'),
            ],
        );
    }
}

it('registers a custom node via the facade and makes it resolvable and catalogued', function (): void {
    PageBuilder::registerNode(new TestSlugifyNode);

    // Resolvable by the flow runner.
    $handler = app(NodeRegistry::class)->get('test_slugify');
    expect($handler)->toBeInstanceOf(TestSlugifyNode::class);

    // Present in the registry definitions with its rich metadata...
    $nodeKeys = collect(app(NodeRegistry::class)->definitions())
        ->map(fn (CapabilityDefinition $d): string => $d->key);
    expect($nodeKeys)->toContain('test_slugify');

    // ...and in the merged capability catalogue as a 'node'.
    $entry = collect(PageBuilder::capabilities())->firstWhere('key', 'test_slugify');
    expect($entry)->not->toBeNull()
        ->and($entry['kind'])->toBe(CapabilityDefinition::KIND_NODE)
        ->and($entry['label'])->toBe('Test Slugify')
        ->and($entry['inputs'])->toHaveCount(2);
});

it('registers a custom helper via the facade — has(), catalogue, and sandbox call', function (): void {
    PageBuilder::registerHelper(
        new CapabilityDefinition(
            key: 'test_shout',
            label: 'util.shout',
            category: CapabilityCategory::Util,
            kind: CapabilityDefinition::KIND_HELPER,
            description: 'Uppercases a string.',
            usage: 'test_shout("hi")',
            inputs: [new CapabilityInput('text', 'Text', 'string', required: true)],
        ),
        static fn (string $text): string => strtoupper($text),
    );

    // Known to the helper registry.
    expect(app(HelperRegistry::class)->has('test_shout'))->toBeTrue();

    // Catalogued as a 'helper'.
    $entry = collect(PageBuilder::capabilities())->firstWhere('key', 'test_shout');
    expect($entry)->not->toBeNull()
        ->and($entry['kind'])->toBe(CapabilityDefinition::KIND_HELPER);

    // Bonus: callable through the expression sandbox.
    // (Build the evaluator AFTER registering so it picks up the new helper.)
    $result = app(ExpressionEvaluator::class)->evaluate('test_shout("hi")');
    expect($result)->toBe('HI');
});

it('exposes both nodes and helpers in the merged capabilities catalogue', function (): void {
    $kinds = collect(PageBuilder::capabilities())
        ->pluck('kind')
        ->unique()
        ->values()
        ->all();

    expect($kinds)->toContain(CapabilityDefinition::KIND_NODE)
        ->and($kinds)->toContain(CapabilityDefinition::KIND_HELPER);
});
