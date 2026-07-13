<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Models\PbSetting;
use Andre\AiPageBuilder\Services\MediaStorage;
use Andre\AiPageBuilder\Services\Settings;
use Andre\AiPageBuilder\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function configureS3(Settings $settings): void
{
    $settings->set('storage.driver', 's3');
    $settings->set('storage.s3.key', 'AKIAEXAMPLE');
    $settings->setEncrypted('storage.s3.secret', 'super-secret');
    $settings->set('storage.s3.region', 'eu-west-1');
    $settings->set('storage.s3.bucket', 'synapse-media');
}

it('detects which adapter packages are installed', function (): void {
    $storage = app(MediaStorage::class);

    // league/flysystem-aws-s3-v3 is a dev dependency; azure/gcs are not.
    expect($storage->installed('s3'))->toBeTrue()
        ->and($storage->installed('azure'))->toBeFalse()
        ->and($storage->installed('gcs'))->toBeFalse()
        ->and($storage->installed('nope'))->toBeFalse();
});

it('defaults to the local driver and the config media disk', function (): void {
    $storage = app(MediaStorage::class);

    expect($storage->driver())->toBe('local')
        ->and($storage->configured())->toBeFalse()
        ->and($storage->usable())->toBeFalse()
        ->and($storage->diskName())->toBe('public');
});

it('treats an unknown stored driver as local', function (): void {
    app(Settings::class)->set('storage.driver', 'dropbox');

    expect(app(MediaStorage::class)->driver())->toBe('local');
});

it('resolves the cloud disk once s3 is fully configured', function (): void {
    $settings = app(Settings::class);
    $storage = app(MediaStorage::class);

    $settings->set('storage.driver', 's3');
    $settings->set('storage.s3.key', 'AKIAEXAMPLE');
    expect($storage->configured())->toBeFalse()
        ->and($storage->diskName())->toBe('public');

    configureS3($settings);

    expect($storage->configured())->toBeTrue()
        ->and($storage->usable())->toBeTrue()
        ->and($storage->diskName())->toBe(MediaStorage::DISK);
});

it('is not usable when configured but the adapter package is missing', function (): void {
    $settings = app(Settings::class);
    $settings->set('storage.driver', 'azure');
    $settings->setEncrypted('storage.azure.connection_string', 'AccountName=demo;AccountKey=xyz');
    $settings->set('storage.azure.container', 'media');

    $storage = app(MediaStorage::class);

    expect($storage->configured())->toBeTrue()
        ->and($storage->usable())->toBeFalse()
        ->and($storage->diskName())->toBe('public')
        ->and($storage->unusableReason())->toContain('azure-oss/storage-blob-flysystem');
});

it('stores cloud secrets encrypted at rest', function (): void {
    app(Settings::class)->setEncrypted('storage.s3.secret', 'super-secret');

    $raw = PbSetting::query()->where('key', 'storage.s3.secret')->value('value');

    expect($raw)->not->toContain('super-secret')
        ->and(app(Settings::class)->getEncrypted('storage.s3.secret'))->toBe('super-secret');
});

it('registers a named cloud disk from the stored settings', function (): void {
    configureS3(app(Settings::class));

    app(MediaStorage::class)->registerDisk();

    $disk = config('filesystems.disks.'.MediaStorage::DISK);
    expect($disk['driver'])->toBe('s3')
        ->and($disk['bucket'])->toBe('synapse-media')
        ->and($disk['region'])->toBe('eu-west-1')
        ->and($disk['secret'])->toBe('super-secret')
        ->and($disk)->not->toHaveKeys(['endpoint', 'url']);
});

it('registers no disk when cloud storage is not usable', function (): void {
    app(MediaStorage::class)->registerDisk();

    expect(config('filesystems.disks.'.MediaStorage::DISK))->toBeNull();
});

it('never breaks when the settings table is missing', function (): void {
    Schema::drop(Andre\AiPageBuilder\Support\Schema::table('settings'));
    app(Settings::class)->flush();

    $storage = app(MediaStorage::class);
    $storage->registerDisk();

    expect($storage->driver())->toBe('local')
        ->and($storage->diskName())->toBe('public')
        ->and(config('filesystems.disks.'.MediaStorage::DISK))->toBeNull();
});

it('uploads media onto the configured cloud disk', function (): void {
    configureS3(app(Settings::class));
    app(MediaStorage::class)->registerDisk();
    Storage::fake(MediaStorage::DISK);

    $this->actingAs(new User);

    // ->create() not ->image(): the test host may lack GD (see PublicUploadTest).
    $res = $this->postJson('/ai-page-builder/media/upload', [
        'files' => [UploadedFile::fake()->create('hero.png', 10, 'image/png')],
    ]);

    $res->assertOk();

    $item = MediaItem::first();
    expect($item->disk)->toBe(MediaStorage::DISK);
    Storage::disk(MediaStorage::DISK)->assertExists($item->path());
});

it('returns absolute urls for media on a remote cloud disk', function (): void {
    config(['filesystems.disks.'.MediaStorage::DISK => [
        'driver' => 'local',
        'root' => storage_path('framework/testing/pb-cloud'),
        'url' => 'https://cdn.example.com/media',
    ]]);

    $item = MediaItem::factory()->create(['disk' => MediaStorage::DISK]);

    expect($item->url())
        ->toBe('https://cdn.example.com/media/'.$item->path())
        ->and($item->toAsset()['src'])->toStartWith('https://cdn.example.com/');
});

it('keeps urls absolute for a same-host different-port disk (e.g. local MinIO)', function (): void {
    // app.url host is `localhost` — a MinIO bucket on localhost:9000 is a
    // DIFFERENT origin and must not be host-stripped to the app origin.
    config(['app.url' => 'http://localhost']);
    config(['filesystems.disks.'.MediaStorage::DISK => [
        'driver' => 'local',
        'root' => storage_path('framework/testing/pb-cloud'),
        'url' => 'http://localhost:9000/synapse-media',
    ]]);

    $item = MediaItem::factory()->create(['disk' => MediaStorage::DISK]);

    expect($item->url())->toBe('http://localhost:9000/synapse-media/'.$item->path());
});

it('reports per-driver visibility support', function (): void {
    $settings = app(Settings::class);
    $storage = app(MediaStorage::class);

    // Local / not usable → default true (host disk decides).
    expect($storage->supportsVisibility())->toBeTrue();

    configureS3($settings);
    expect($storage->supportsVisibility())->toBeTrue();
});

it('builds a config array for each cloud driver', function (): void {
    $settings = app(Settings::class);
    $storage = app(MediaStorage::class);

    configureS3($settings);
    $settings->set('storage.s3.endpoint', 'http://minio:9000');
    $settings->set('storage.s3.path_style', true);
    $settings->set('storage.s3.url', 'https://cdn.example.com');

    expect($storage->diskConfig())->toMatchArray([
        'driver' => 's3',
        'endpoint' => 'http://minio:9000',
        'use_path_style_endpoint' => true,
        'url' => 'https://cdn.example.com',
    ]);

    $settings->set('storage.driver', 'azure');
    $settings->setEncrypted('storage.azure.connection_string', 'AccountName=demo;AccountKey=xyz;EndpointSuffix=core.windows.net');
    $settings->set('storage.azure.container', 'media');

    expect($storage->diskConfig())->toMatchArray([
        'driver' => 'pb-azure',
        'container' => 'media',
        'url' => 'https://demo.blob.core.windows.net/media',
    ]);

    $settings->set('storage.driver', 'gcs');
    $settings->setEncrypted('storage.gcs.key_json', '{"type":"service_account"}');
    $settings->set('storage.gcs.bucket', 'synapse-media');

    expect($storage->diskConfig())->toMatchArray([
        'driver' => 'pb-gcs',
        'bucket' => 'synapse-media',
        'uniform_acl' => true,
        'url' => 'https://storage.googleapis.com/synapse-media',
    ]);
});

it('probes the connection against the current settings', function (): void {
    configureS3(app(Settings::class));

    // Point the just-built disk at the local driver so the probe can run
    // without a real bucket: Storage::build() honours the config array, so
    // override diskConfig via a fake registered under the same name instead.
    $storage = app(MediaStorage::class);

    // Unusable → actionable failure.
    app(Settings::class)->set('storage.driver', 'local');
    expect(fn () => $storage->testConnection())
        ->toThrow(RuntimeException::class, 'No cloud storage driver is selected');
});
