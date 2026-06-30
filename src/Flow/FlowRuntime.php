<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

/**
 * Holds the FlowContext of the run currently executing, so a function helper
 * invoked deep inside the expression sandbox (e.g. ui_notify, ui_redirect,
 * ui_logout) can reach the run's action buffer — the same buffer a Result node
 * writes to. {@see FlowRunner} sets the context for the duration of a run and
 * clears it afterwards; outside a flow run `context()` is null and UI helpers
 * become graceful no-ops.
 */
class FlowRuntime
{
    private ?FlowContext $context = null;

    public function setContext(?FlowContext $context): void
    {
        $this->context = $context;
    }

    public function context(): ?FlowContext
    {
        return $this->context;
    }
}
