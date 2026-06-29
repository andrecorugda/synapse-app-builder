<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Demo\InventoryDemo;
use Andre\AiPageBuilder\Demo\MarketingDemo;
use Illuminate\Console\Command;

/**
 * Installs two showcase apps built entirely from the package's own primitives —
 * a designed marketing website and a role-gated Inventory CRUD app (collections,
 * a reactive dashboard, States, a Function, a branching Flow, and end-user
 * roles/permissions). A one-command tour of what Synapse can build.
 */
class InstallDemoCommand extends Command
{
    protected $signature = 'ai-page-builder:install-demo';

    protected $description = 'Install the showcase demo apps (a marketing website + a role-gated Inventory CRUD app).';

    public function handle(MarketingDemo $marketing, InventoryDemo $inventory): int
    {
        $this->info('Installing Synapse demo apps…');

        $marketing->build();
        $this->line('  ✓ Marketing website  → /p/home');

        $inventory->build();
        $this->line('  ✓ Inventory app      → /p/inventory  (sign in: manager@nimbus.test / password)');

        $this->newLine();
        $this->info('Done. Open the published pages above; the inventory dashboard is login-gated.');
        $this->comment('Roles seeded: Inventory Manager (full) and Warehouse Staff (read-only products).');

        return self::SUCCESS;
    }
}
