<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fill: every legacy `trigger_type='collection'` flow becomes one Watcher
 * row per event it listened to, so the same automation keeps working now that
 * collection dispatch runs off Watchers instead of the flow's trigger_config.
 *
 * One watcher per (collection, event) → this flow, which is also what gives
 * each event its own separable target going forward. Idempotent: skips a
 * (source_key, event, target_key) that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conn = PbSchema::connection();
        $flows = PbSchema::table('flows');
        $watchers = PbSchema::table('watchers');

        $db = DB::connection($conn);

        // Guard: nothing to do if either table is absent (fresh install ordering).
        if (! $db->getSchemaBuilder()->hasTable($flows) || ! $db->getSchemaBuilder()->hasTable($watchers)) {
            return;
        }

        $rows = $db->table($flows)
            ->where('trigger_type', 'collection')
            ->whereNull('deleted_at')
            ->get(['slug', 'name', 'trigger_config', 'is_active']);

        $now = now();

        foreach ($rows as $flow) {
            $config = json_decode((string) ($flow->trigger_config ?? '{}'), true) ?: [];
            $collection = $config['collection'] ?? null;
            $events = (array) ($config['events'] ?? []);
            $criteria = $config['criteria'] ?? [];

            if (! is_string($collection) || $collection === '' || $events === []) {
                continue;
            }

            foreach ($events as $event) {
                $exists = $db->table($watchers)
                    ->where('source_type', 'collection')
                    ->where('source_key', $collection)
                    ->where('event', $event)
                    ->where('target_key', $flow->slug)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $db->table($watchers)->insert([
                    'name' => sprintf('%s · %s', $flow->name, $event),
                    'source_type' => 'collection',
                    'source_key' => $collection,
                    'event' => $event,
                    'config' => $criteria === [] ? null : json_encode(['criteria' => $criteria]),
                    'target_type' => 'flow',
                    'target_key' => $flow->slug,
                    'input_map' => null,
                    'is_active' => (bool) $flow->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: leave back-filled watchers in place. Removing them
        // would silently break automation for anyone who has since edited them.
    }
};
