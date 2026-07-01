<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

/**
 * Describes every UI action type the page runtime (flow-runtime.blade.php) can
 * apply. Each entry is a structured descriptor that the flow canvas can use to
 * build a low-code "actions builder" panel — instead of asking the author to hand-
 * write a JSON array, the panel renders a form per action type and serialises it.
 *
 * Field descriptors intentionally mirror CapabilityInput::toArray() shape so the
 * front-end can reuse the same control-renderers it already uses for node config:
 *   { key, label, type, options?, required? }
 *
 * The catalog is surfaced through ResultNode's `actions` input (type "actions"),
 * which carries this catalog under the `options` key. It therefore appears in
 * the serialised nodeDefs as:
 *   nodeDefs.result.inputs[0].options = { notify: {...}, alert: {...}, ... }
 */
final class ResultActionCatalog
{
    /**
     * Returns an ordered map of action-type descriptors, keyed by the `type`
     * string the runtime's applyAction() function dispatches on.
     *
     * @return array<string, array{label: string, fields: list<array<string,mixed>>}>
     */
    public static function types(): array
    {
        return [
            'notify' => [
                'label' => 'Notify (toast)',
                'fields' => [
                    ['key' => 'message', 'label' => 'Message', 'type' => 'text', 'required' => true],
                    ['key' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => [
                        'success' => 'Success',
                        'error' => 'Error',
                        'info' => 'Info',
                        'warning' => 'Warning',
                    ]],
                ],
            ],

            'alert' => [
                'label' => 'Alert (dialog)',
                'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'message', 'label' => 'Message', 'type' => 'text', 'required' => true],
                ],
            ],

            'modal' => [
                'label' => 'Modal',
                'fields' => [
                    ['key' => 'target', 'label' => 'Target selector', 'type' => 'string', 'required' => true],
                    ['key' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => [
                        'open' => 'Open',
                        'close' => 'Close',
                    ]],
                    ['key' => 'html', 'label' => 'HTML content', 'type' => 'text', 'show_if' => ['action' => ['open']]],
                ],
            ],

            'redirect' => [
                'label' => 'Redirect',
                'fields' => [
                    ['key' => 'url', 'label' => 'URL', 'type' => 'string', 'required' => true],
                ],
            ],

            'setState' => [
                'label' => 'Set state (reactive store)',
                'fields' => [
                    ['key' => 'key', 'label' => 'State key', 'type' => 'string', 'required' => true],
                    ['key' => 'value', 'label' => 'Value', 'type' => 'expression', 'required' => true],
                ],
            ],

            'setStates' => [
                'label' => 'Set states (bulk)',
                'fields' => [
                    ['key' => 'values', 'label' => 'Values (key → value map)', 'type' => 'keyvalue', 'required' => true],
                ],
            ],

            'setHtml' => [
                'label' => 'Set HTML',
                'fields' => [
                    ['key' => 'target', 'label' => 'Target selector', 'type' => 'string', 'required' => true],
                    ['key' => 'html', 'label' => 'HTML', 'type' => 'text', 'required' => true],
                ],
            ],

            'setText' => [
                'label' => 'Set text',
                'fields' => [
                    ['key' => 'target', 'label' => 'Target selector', 'type' => 'string', 'required' => true],
                    ['key' => 'text', 'label' => 'Text', 'type' => 'text', 'required' => true],
                ],
            ],

            'addClass' => [
                'label' => 'Add CSS class',
                'fields' => [
                    ['key' => 'target', 'label' => 'Target selector', 'type' => 'string', 'required' => true],
                    ['key' => 'class', 'label' => 'Class(es)', 'type' => 'string', 'required' => true],
                ],
            ],

            'removeClass' => [
                'label' => 'Remove CSS class',
                'fields' => [
                    ['key' => 'target', 'label' => 'Target selector', 'type' => 'string', 'required' => true],
                    ['key' => 'class', 'label' => 'Class(es)', 'type' => 'string', 'required' => true],
                ],
            ],

            'logout' => [
                'label' => 'Logout',
                'fields' => [
                    ['key' => 'url', 'label' => 'Redirect URL after logout (optional)', 'type' => 'string'],
                ],
            ],
        ];
    }
}
