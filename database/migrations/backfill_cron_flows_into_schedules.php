<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fill: surface every legacy `trigger_type='cron'` flow as a Schedule row
 * so cron automations move to the first-class, per-flow-cadence Schedules model.
 *
 * Legacy cron flows carry NO cron expression — the `ai-page-builder:run-cron-flows`
 * command runs them all on every invocation. We therefore CANNOT infer the real
 * cadence, so the created Schedules are seeded INACTIVE with a placeholder
 * expression and a "(review cadence)" name — a human sets the real schedule and
 * activates it. The legacy command keeps working meanwhile, so nothing changes
 * execution timing silently. Idempotent: skips a flow that already has a
 * flow-targeted schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conn = PbSchema::connection();
        $flows = PbSchema::table('flows');
        $schedules = PbSchema::table('schedules');

        $db = DB::connection($conn);

        if (! $db->getSchemaBuilder()->hasTable($flows) || ! $db->getSchemaBuilder()->hasTable($schedules)) {
            return;
        }

        $rows = $db->table($flows)
            ->where('trigger_type', 'cron')
            ->whereNull('deleted_at')
            ->get(['slug', 'name']);

        $now = now();

        foreach ($rows as $flow) {
            $exists = $db->table($schedules)
                ->where('target_type', 'flow')
                ->where('target_key', $flow->slug)
                ->exists();

            if ($exists) {
                continue;
            }

            $db->table($schedules)->insert([
                'name' => sprintf('%s (review cadence)', $flow->name),
                'cron_expression' => '0 * * * *', // placeholder — set the real cadence before activating
                'target_type' => 'flow',
                'target_key' => $flow->slug,
                'args' => null,
                'timezone' => null,
                'is_active' => false, // inactive until a human confirms the cadence
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive: leave any reviewed/activated schedules in place.
    }
};
