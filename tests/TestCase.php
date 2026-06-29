<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Tests;

use Andre\AiPageBuilder\AiPageBuilderServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../database/migrations/create_pages_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_media_table.php')->up();
        (require __DIR__.'/../database/migrations/add_custom_css_to_pages_table.php')->up();
        (require __DIR__.'/../database/migrations/add_custom_js_to_pages_table.php')->up();
        (require __DIR__.'/../database/migrations/add_kind_to_pages_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_flows_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_flow_runs_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_functions_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_models_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_fields_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_variables_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_settings_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_roles_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_users_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_permissions_table.php')->up();
        (require __DIR__.'/../database/migrations/add_requires_auth_to_pages_table.php')->up();
        (require __DIR__.'/../database/migrations/create_schedules_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_revisions_table.php')->up();
    }

    /**
     * @param  Application  $app
     * @return array<int,class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiPageBuilderServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('cache.default', 'array');

        // The render route runs through the `web` middleware group, whose
        // cookie encryption needs an app key under Testbench.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
