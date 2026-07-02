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
        (require __DIR__.'/../database/migrations/create_page_builder_api_tokens_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_partials_table.php')->up();
        (require __DIR__.'/../database/migrations/add_project_data_to_page_builder_partials_table.php')->up();
        (require __DIR__.'/../database/migrations/add_custom_fields_to_page_builder_partials_table.php')->up();
        (require __DIR__.'/../database/migrations/add_fields_to_permissions_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_credentials_table.php')->up();
        (require __DIR__.'/../database/migrations/add_auth_fields_to_page_builder_users_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_password_resets_table.php')->up();
        (require __DIR__.'/../database/migrations/add_sso_fields_to_page_builder_users_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_user_invites_table.php')->up();
        (require __DIR__.'/../database/migrations/add_two_factor_fields_to_page_builder_users_table.php')->up();
        (require __DIR__.'/../database/migrations/add_external_source_to_page_builder_models_table.php')->up();
        (require __DIR__.'/../database/migrations/add_display_field_to_page_builder_models_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_record_revisions_table.php')->up();
        (require __DIR__.'/../database/migrations/create_page_builder_watchers_table.php')->up();
        (require __DIR__.'/../database/migrations/add_watcher_id_to_page_builder_flow_runs_table.php')->up();

        // HTTP-level tests model same-origin browser requests: a real browser
        // attaches an Origin header to every state-changing fetch, which the
        // data API's EnsureDataApiSameOrigin (CSRF) middleware requires. The
        // Testbench client runs under host `localhost`, so default to a matching
        // Origin. Cross-origin rejection is covered in CsrfTest by exercising the
        // middleware directly.
        $this->withHeader('Origin', 'http://localhost');
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
