<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem as Flysystem;
use RuntimeException;

/**
 * Cloud storage for the media library — Amazon S3, Azure Blob Storage or
 * Google Cloud Storage, configured by the admin on the Settings screen.
 *
 * Credentials come from the {@see Settings} service (the `storage.*` keys;
 * secrets via setEncrypted, never plaintext at rest). When a cloud driver is
 * fully configured AND its Flysystem adapter package is installed, a named
 * disk (`pb-cloud`) is registered at boot and becomes the media disk — the
 * name is persisted on each MediaItem row, so URLs keep resolving per-row.
 * Otherwise everything falls back to the host-app disk from
 * `ai-page-builder.media.disk`, byte-for-byte today's behaviour.
 *
 * The adapter packages are OPTIONAL, so their classes are referenced as
 * string literals (never imported) and guarded by class_exists — mirroring
 * SocialProviders (Socialite) and GatewayAiInvoker (AI gateway).
 */
class MediaStorage
{
    /** The runtime-registered disk name persisted on MediaItem rows. */
    public const DISK = 'pb-cloud';

    /** @var array<int,string> */
    public const DRIVERS = ['s3', 'azure', 'gcs'];

    /** @var array<string,string> */
    private const LABELS = [
        's3' => 'Amazon S3',
        'azure' => 'Azure Blob Storage',
        'gcs' => 'Google Cloud Storage',
    ];

    /** @var array<string,string> Adapter class per driver (string literals — optional deps). */
    private const ADAPTERS = [
        's3' => 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter',
        'azure' => 'AzureOss\\Storage\\BlobFlysystem\\AzureBlobStorageAdapter',
        'gcs' => 'League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter',
    ];

    /** @var array<string,string> Composer package that provides each adapter. */
    private const PACKAGES = [
        's3' => 'league/flysystem-aws-s3-v3',
        'azure' => 'azure-oss/storage-blob-flysystem',
        'gcs' => 'league/flysystem-google-cloud-storage',
    ];

    private const AZURE_SERVICE_CLIENT = 'AzureOss\\Storage\\Blob\\BlobServiceClient';

    private const GCS_STORAGE_CLIENT = 'Google\\Cloud\\Storage\\StorageClient';

    private const GCS_UNIFORM_VISIBILITY = 'League\\Flysystem\\GoogleCloudStorage\\UniformBucketLevelAccessVisibility';

    public function __construct(private readonly Settings $settings) {}

    /**
     * The selected storage driver: `local` (default — use the host-app disk)
     * or one of DRIVERS.
     */
    public function driver(): string
    {
        $driver = strtolower(trim((string) $this->settings->get('storage.driver', 'local')));

        return in_array($driver, self::DRIVERS, true) ? $driver : 'local';
    }

    public function label(string $driver): string
    {
        return self::LABELS[$driver] ?? ucfirst($driver);
    }

    /** The composer package that unlocks a driver (null for unknown). */
    public function package(string $driver): ?string
    {
        return self::PACKAGES[$driver] ?? null;
    }

    /** True when the Flysystem adapter package for the driver is installed. */
    public function installed(string $driver): bool
    {
        return isset(self::ADAPTERS[$driver]) && class_exists(self::ADAPTERS[$driver]);
    }

    /** True when a cloud driver is selected and its required credentials are set. */
    public function configured(): bool
    {
        return match ($this->driver()) {
            's3' => $this->str('storage.s3.key') !== ''
                && $this->secret('storage.s3.secret') !== ''
                && $this->str('storage.s3.region') !== ''
                && $this->str('storage.s3.bucket') !== '',
            'azure' => $this->secret('storage.azure.connection_string') !== ''
                && $this->str('storage.azure.container') !== '',
            'gcs' => $this->secret('storage.gcs.key_json') !== ''
                && $this->str('storage.gcs.bucket') !== '',
            default => false,
        };
    }

    /** A cloud disk can be offered only when selected, credentialed, and the adapter is present. */
    public function usable(): bool
    {
        $driver = $this->driver();

        return $driver !== 'local' && $this->configured() && $this->installed($driver);
    }

    /**
     * The disk new media should be written to: the runtime cloud disk when
     * usable, else the host-app disk from config (today's behaviour).
     */
    public function diskName(): string
    {
        return $this->usable() ? self::DISK : (string) config('ai-page-builder.media.disk', 'public');
    }

    /**
     * Whether the active disk accepts a per-file `public` visibility option.
     * Azure has container-level access only; GCS buckets with uniform
     * bucket-level access reject per-object ACLs.
     */
    public function supportsVisibility(): bool
    {
        if (! $this->usable()) {
            return true;
        }

        return match ($this->driver()) {
            'azure' => false,
            'gcs' => ! (bool) $this->settings->get('storage.gcs.uniform_acl', true),
            default => true,
        };
    }

    /**
     * The `filesystems.disks` entry for the active cloud driver.
     *
     * @return array<string,mixed>
     */
    public function diskConfig(): array
    {
        return match ($this->driver()) {
            's3' => array_filter([
                'driver' => 's3',
                'key' => $this->str('storage.s3.key'),
                'secret' => $this->secret('storage.s3.secret'),
                'region' => $this->str('storage.s3.region'),
                'bucket' => $this->str('storage.s3.bucket'),
                'endpoint' => $this->str('storage.s3.endpoint') ?: null,
                'use_path_style_endpoint' => (bool) $this->settings->get('storage.s3.path_style', false),
                'url' => $this->str('storage.s3.url') ?: null,
                'visibility' => 'public',
                'throw' => false,
            ], static fn (mixed $v): bool => $v !== null),
            'azure' => [
                'driver' => 'pb-azure',
                'connection_string' => $this->secret('storage.azure.connection_string'),
                'container' => $this->str('storage.azure.container'),
                'url' => $this->str('storage.azure.url') ?: $this->defaultAzureUrl(),
                'throw' => false,
            ],
            'gcs' => [
                'driver' => 'pb-gcs',
                'key_json' => $this->secret('storage.gcs.key_json'),
                'bucket' => $this->str('storage.gcs.bucket'),
                'uniform_acl' => (bool) $this->settings->get('storage.gcs.uniform_acl', true),
                'url' => $this->str('storage.gcs.url')
                    ?: 'https://storage.googleapis.com/'.$this->str('storage.gcs.bucket'),
                'throw' => false,
            ],
            default => [],
        };
    }

    /**
     * Publish the runtime cloud disk into `filesystems.disks` (no-op unless
     * usable). Registering by NAME — not Storage::build() — matters: MediaItem
     * rows persist a disk name, and both url() and Filament FileUpload resolve
     * disks by name.
     */
    public function registerDisk(): void
    {
        if (! $this->usable()) {
            return;
        }

        config(['filesystems.disks.'.self::DISK => $this->diskConfig()]);
    }

    /**
     * Register the custom Azure / GCS driver creators (S3 rides Laravel's
     * native `s3` driver). Cheap closures — safe to register unconditionally;
     * they only run when a disk with that driver is resolved, and they throw
     * an actionable message when the adapter package is missing.
     */
    public function registerDrivers(): void
    {
        Storage::extend('pb-azure', fn ($app, array $config): FilesystemAdapter => $this->buildAzure($config));
        Storage::extend('pb-gcs', fn ($app, array $config): FilesystemAdapter => $this->buildGcs($config));
    }

    /**
     * Probe the CURRENT settings (not the boot-time snapshot) with a write /
     * read-back / URL / delete round-trip on an on-demand disk.
     *
     * @throws RuntimeException When unusable or any probe step fails.
     */
    public function testConnection(): void
    {
        if (! $this->usable()) {
            throw new RuntimeException($this->unusableReason());
        }

        $disk = Storage::build(array_replace($this->diskConfig(), ['throw' => true]));

        $dir = trim((string) config('ai-page-builder.media.directory', 'page-builder'), '/');
        $path = $dir.'/pb-storage-probe-'.Str::random(16).'.txt';
        $payload = 'synapse-storage-probe '.now()->toIso8601String();

        $disk->put($path, $payload);

        try {
            if ($disk->get($path) !== $payload) {
                throw new RuntimeException('The probe file read back with different contents.');
            }

            if (trim($disk->url($path)) === '') {
                throw new RuntimeException('The disk did not produce a public URL for the probe file.');
            }
        } finally {
            $disk->delete($path);
        }
    }

    /** Human explanation for why the cloud disk is not usable (for CLI / UI errors). */
    public function unusableReason(): string
    {
        $driver = $this->driver();

        if ($driver === 'local') {
            return 'No cloud storage driver is selected — choose one on the Settings → Storage tab.';
        }

        if (! $this->installed($driver)) {
            return sprintf(
                '%s support requires the %s package — composer require %s.',
                $this->label($driver),
                $this->package($driver),
                $this->package($driver),
            );
        }

        return sprintf('%s is not fully configured — fill in the missing credentials on the Settings → Storage tab.', $this->label($driver));
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function buildAzure(array $config): FilesystemAdapter
    {
        if (! $this->installed('azure')) {
            throw new RuntimeException($this->installHint('azure'));
        }

        $serviceClient = self::AZURE_SERVICE_CLIENT;
        $container = $serviceClient::fromConnectionString((string) ($config['connection_string'] ?? ''))
            ->getContainerClient((string) ($config['container'] ?? ''));

        $adapterClass = self::ADAPTERS['azure'];
        $adapter = new $adapterClass($container);

        return new FilesystemAdapter(new Flysystem($adapter, $config), $adapter, $config);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function buildGcs(array $config): FilesystemAdapter
    {
        if (! $this->installed('gcs')) {
            throw new RuntimeException($this->installHint('gcs'));
        }

        $keyFile = json_decode((string) ($config['key_json'] ?? ''), true);
        if (! is_array($keyFile)) {
            throw new RuntimeException('The Google Cloud service-account key must be valid JSON.');
        }

        $clientClass = self::GCS_STORAGE_CLIENT;
        $bucket = (new $clientClass(['keyFile' => $keyFile]))->bucket((string) ($config['bucket'] ?? ''));

        // Buckets with uniform bucket-level access (the modern GCS default)
        // reject per-object ACLs — use the adapter's no-op visibility handler
        // there; otherwise default new objects to publicly readable.
        $adapterClass = self::ADAPTERS['gcs'];
        if (! empty($config['uniform_acl'])) {
            $handlerClass = self::GCS_UNIFORM_VISIBILITY;
            $adapter = new $adapterClass($bucket, '', new $handlerClass);
        } else {
            $adapter = new $adapterClass($bucket, '', null, 'public');
        }

        return new FilesystemAdapter(new Flysystem($adapter, $config), $adapter, $config);
    }

    private function installHint(string $driver): string
    {
        return sprintf(
            '%s media storage requires the %s package — composer require %s.',
            $this->label($driver),
            $this->package($driver),
            $this->package($driver),
        );
    }

    /**
     * Default public base URL for Azure: honour an explicit BlobEndpoint in
     * the connection string (Azurite / sovereign clouds), else the standard
     * `https://{account}.blob.core.windows.net/{container}` form.
     */
    private function defaultAzureUrl(): string
    {
        $cs = $this->secret('storage.azure.connection_string');
        $container = $this->str('storage.azure.container');

        $parts = [];
        foreach (explode(';', $cs) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[strtolower(trim($k))] = trim($v);
        }

        if (($parts['blobendpoint'] ?? '') !== '') {
            return rtrim($parts['blobendpoint'], '/').'/'.$container;
        }

        $account = $parts['accountname'] ?? '';
        $suffix = ($parts['endpointsuffix'] ?? '') !== '' ? $parts['endpointsuffix'] : 'core.windows.net';

        return sprintf('https://%s.blob.%s/%s', $account, $suffix, $container);
    }

    private function str(string $key): string
    {
        return trim((string) $this->settings->get($key, ''));
    }

    private function secret(string $key): string
    {
        return trim((string) ($this->settings->getEncrypted($key) ?? ''));
    }
}
