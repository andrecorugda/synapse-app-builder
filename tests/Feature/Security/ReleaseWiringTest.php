<?php

declare(strict_types=1);

use Andre\AiPageBuilder\AiPageBuilderServiceProvider;
use Illuminate\Support\Facades\Artisan;

/*
 * 2.0.0 ships two migrations and two console commands that upgraders are told
 * to run. Pin that the service provider registers them, so an install gets
 * them without the tests' own wiring.
 */

it('registers the 2.0 security console commands', function (string $command): void {
    expect(Artisan::all())->toHaveKey($command);
})->with(['ai-page-builder:states', 'ai-page-builder:credential-hosts']);

it('publishes the 2.0 security migrations', function (string $migration): void {
    $source = file_get_contents((new ReflectionClass(AiPageBuilderServiceProvider::class))->getFileName());

    expect($source)->toContain("'{$migration}'")
        ->and(file_exists(__DIR__."/../../../database/migrations/{$migration}.php"))->toBeTrue();
})->with([
    'add_is_public_to_page_builder_variables_table',
    'add_allowed_hosts_to_page_builder_credentials_table',
]);
