<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\Exceptions\FlowBodyFailed;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Flow\Nodes\Concerns\ResolvesFlowBody;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs its body sub-graph inside a database transaction. If every node in the
 * body succeeds the transaction COMMITS and the flow follows the `committed`
 * branch; if any node fails (throws, or soft-fails via FlowContext::$failed) the
 * transaction ROLLS BACK every write and the flow follows the `rolled_back`
 * branch. UI actions emitted by a rolled-back body are discarded too, so a failed
 * attempt leaves neither half-written data nor a misleading toast.
 *
 * config: { body:{start,nodes} }; node-level branches: committed, rolled_back.
 *
 * This is the safe, eval-free way to express an atomic multi-write operation —
 * e.g. a POS checkout that creates an order, decrements stock for each line item
 * (a Loop in the body), and records a payment, all-or-nothing.
 */
class TransactionNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    use ResolvesFlowBody;

    public function type(): string
    {
        return 'transaction';
    }

    public function run(array $node, FlowContext $context): array
    {
        $body = $this->resolveBody((array) ($node['config'] ?? []));
        if ($body === null) {
            return $this->committedBranch($node);
        }

        $runner = app(FlowRunner::class);
        $actionsBefore = count($context->actions);
        $committed = true;
        $failure = null;

        try {
            DB::connection(Schema::connection())->transaction(function () use ($runner, $body, $context): void {
                $runner->runBody($body, $context);

                if ($context->failed) {
                    throw new FlowBodyFailed($context->error ?? 'Transaction body failed.');
                }
            });
        } catch (\Throwable $e) {
            $committed = false;
            // Handled here via the rolled_back branch, so clear the soft-fail flag
            // and discard any UI actions the now-rolled-back body queued. Expose
            // the reason to the branch as `vars.error`, but clear the RUN-level
            // error: a transaction that rolled back and took its rolled_back
            // branch completed as designed, so telemetry records it `ok` — not an
            // "ok" run carrying a stray error message.
            $failure = $e->getMessage();
            $context->failed = false;
            $context->error = null;
            $context->set('error', $failure);
            array_splice($context->actions, $actionsBefore);
        }

        if ($committed) {
            return $this->committedBranch($node);
        }

        $rolledBack = $node['rolled_back'] ?? null;
        if (is_string($rolledBack) || is_array($rolledBack)) {
            return (array) $rolledBack;
        }

        // No rollback branch wired — surface the failure like any other node
        // error (this DOES fail the run; the walk records it and toasts).
        throw new RuntimeException($failure ?? 'Transaction rolled back.');
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    private function committedBranch(array $node): array
    {
        return (array) ($node['committed'] ?? $node['next'] ?? []);
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Transaction',
            category: CapabilityCategory::Data,
            description: 'Runs its body atomically: every record write inside commits together, or all roll back if any step fails. Follows the "committed" branch on success and "rolled_back" on failure. Use it to wrap multi-step writes that must not half-apply.',
            usage: 'Body = create order → Loop over items (decrement stock) → record payment. If stock runs out mid-loop, the order and prior decrements all roll back and the flow takes the rolled_back branch.',
            icon: 'shield-check',
            inputs: [
                new CapabilityInput(
                    'body',
                    'Body (atomic sub-flow)',
                    'json',
                    help: 'A {start, nodes} sub-graph that runs atomically inside a database transaction. '
                        .'Every record write in the body commits together on success, or all roll back if any node '
                        .'fails. The canvas "body" handle wires to the first node of the sub-flow; this JSON field '
                        .'is the serialised sub-graph used when importing/exporting the flow definition.',
                ),
            ],
            outputHandles: ['body', 'committed', 'rolled_back'],
            meta: ['has_body' => true],
        );
    }
}
