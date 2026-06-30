<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities\Helpers;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Illuminate\Support\Str;

/**
 * Small general-purpose helpers — dates, ids, numbers, JSON — for shaping values
 * inside a Function without reaching for raw PHP.
 */
class UtilHelpers implements HelperProvider
{
    public function register(HelperRegistry $registry): void
    {
        $registry->register(
            new CapabilityDefinition(
                key: 'util_now',
                label: 'util.now',
                category: CapabilityCategory::Util,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'The current date/time formatted with a PHP date format (default Y-m-d H:i:s).',
                usage: "util_now('Y-m-d')",
                inputs: [new CapabilityInput('format', 'Format', 'string', default: 'Y-m-d H:i:s')],
            ),
            static fn (string $format = 'Y-m-d H:i:s'): string => now()->format($format),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'util_uuid',
                label: 'util.uuid',
                category: CapabilityCategory::Util,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'A new random UUID v4 string.',
                usage: "db_create('orders', {ref: util_uuid()})",
                inputs: [],
            ),
            static fn (): string => Str::uuid()->toString(),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'util_number_format',
                label: 'util.numberFormat',
                category: CapabilityCategory::Util,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Format a number with a fixed number of decimals.',
                usage: 'util_number_format(vars.total, 2)',
                inputs: [
                    new CapabilityInput('number', 'Number', 'number', required: true),
                    new CapabilityInput('decimals', 'Decimals', 'number', default: 2),
                ],
            ),
            static fn (int|float|string $number, int $decimals = 2): string => number_format((float) $number, $decimals, '.', ''),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'util_json_encode',
                label: 'util.jsonEncode',
                category: CapabilityCategory::Util,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Encode a value to a JSON string.',
                usage: 'util_json_encode(vars.payload)',
                inputs: [new CapabilityInput('value', 'Value', 'expression', required: true)],
            ),
            static fn (mixed $value): string => (string) json_encode($value),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'util_json_decode',
                label: 'util.jsonDecode',
                category: CapabilityCategory::Util,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Decode a JSON string to a value.',
                usage: 'util_json_decode(input.body)',
                inputs: [new CapabilityInput('json', 'JSON string', 'string', required: true)],
            ),
            static fn (string $json): mixed => json_decode($json, true),
        );
    }
}
