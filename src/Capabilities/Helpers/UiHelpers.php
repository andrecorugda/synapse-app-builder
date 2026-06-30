<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities\Helpers;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Flow\FlowRuntime;

/**
 * UI feedback helpers — queue a browser action onto the current run's action
 * buffer (the same channel a Result node uses), so a Function can pop a toast,
 * open a modal, redirect, sign the user out, or push reactive state. Outside a
 * flow run (no active context) they are graceful no-ops.
 */
class UiHelpers implements HelperProvider
{
    public function __construct(private readonly FlowRuntime $runtime) {}

    public function register(HelperRegistry $registry): void
    {
        $registry->register(
            new CapabilityDefinition(
                key: 'ui_notify',
                label: 'ui.notify',
                category: CapabilityCategory::Ui,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Show a toast notification to the user.',
                usage: "ui_notify('Order placed!', 'success')",
                inputs: [
                    new CapabilityInput('message', 'Message', 'string', required: true),
                    new CapabilityInput('level', 'Level', 'select', default: 'info', options: ['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'error' => 'error']),
                ],
            ),
            fn (string $message, string $level = 'info'): null => $this->queue(['type' => 'notify', 'message' => $message, 'level' => $level]),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'ui_alert',
                label: 'ui.alert',
                category: CapabilityCategory::Ui,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Show a blocking alert dialog with a title and message.',
                usage: "ui_alert('Out of stock', 'This item just sold out.')",
                inputs: [
                    new CapabilityInput('title', 'Title', 'string', required: true),
                    new CapabilityInput('message', 'Message', 'string'),
                ],
            ),
            fn (string $title, string $message = ''): null => $this->queue(['type' => 'alert', 'title' => $title, 'message' => $message]),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'ui_modal',
                label: 'ui.modal',
                category: CapabilityCategory::Ui,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Open or close a modal on the page by its target selector. Optionally set its inner HTML.',
                usage: "ui_modal('open', '#checkout-modal')",
                inputs: [
                    new CapabilityInput('action', 'Action', 'select', required: true, default: 'open', options: ['open' => 'open', 'close' => 'close']),
                    new CapabilityInput('target', 'Target selector', 'string', required: true),
                    new CapabilityInput('html', 'Inner HTML', 'text'),
                ],
            ),
            fn (string $action, string $target, string $html = ''): null => $this->queue(['type' => 'modal', 'action' => $action, 'target' => $target, 'html' => $html]),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'ui_redirect',
                label: 'ui.redirect',
                category: CapabilityCategory::Ui,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Navigate the browser to a URL.',
                usage: "ui_redirect('/p/order-confirmation')",
                inputs: [new CapabilityInput('url', 'URL', 'string', required: true)],
            ),
            fn (string $url): null => $this->queue(['type' => 'redirect', 'url' => $url]),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'ui_logout',
                label: 'ui.logout',
                category: CapabilityCategory::Auth,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Sign the current end-user out and redirect to the login page.',
                usage: 'ui_logout()',
                inputs: [],
            ),
            fn (): null => $this->queue(['type' => 'logout']),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'ui_set_state',
                label: 'ui.setState',
                category: CapabilityCategory::Ui,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Push a value into the page\'s reactive store ($store.app) so bound components re-render without a reload.',
                usage: "ui_set_state('cart_total', vars.total)",
                inputs: [
                    new CapabilityInput('key', 'State key', 'string', required: true),
                    new CapabilityInput('value', 'Value', 'expression', required: true),
                ],
            ),
            fn (string $key, mixed $value): null => $this->queue(['type' => 'setState', 'key' => $key, 'value' => $value]),
        );
    }

    /**
     * @param  array<string,mixed>  $action
     */
    private function queue(array $action): null
    {
        $this->runtime->context()?->addAction($action);

        return null;
    }
}
