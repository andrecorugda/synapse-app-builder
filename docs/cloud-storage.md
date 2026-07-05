# Cloud media storage

[← Docs index](README.md)

Media uploads normally land on a local Laravel disk (`media.disk`, default `public`). Synapse can instead **offload media to your own bucket** — Amazon S3 (or any S3-compatible service), Azure Blob Storage, or Google Cloud Storage — configured entirely from the admin **Settings → Storage** tab, with credentials **encrypted at rest** in `page_builder_settings`. A migration command moves existing files over and keeps every saved page pointing at them.

Like the [email transport](email.md), cloud storage is **isolated from the host app**: Synapse registers its own runtime disk (named `pb-cloud`) built from its own settings — it never edits your `config/filesystems.php` or touches your app's disks.

## 1. Install the adapter for your provider

The Flysystem adapters are **optional** dependencies — auto-detected when installed, exactly like Socialite for SSO:

| Provider | Composer package |
|---|---|
| Amazon S3 / MinIO / Cloudflare R2 / DO Spaces | `league/flysystem-aws-s3-v3` |
| Azure Blob Storage | `azure-oss/storage-blob-flysystem` |
| Google Cloud Storage | `league/flysystem-google-cloud-storage` |

```bash
composer require league/flysystem-aws-s3-v3   # for S3
```

Without the package, the driver stays greyed out in the Settings picker (with this exact `composer require` hint next to it).

## 2. Configure it in Settings → Storage

Pick a driver and fill in the credentials. Keys stored via the `Settings` service:

| Setting key | Encrypted | Meaning |
|---|---|---|
| `storage.driver` | | `local` (default) / `s3` / `azure` / `gcs` |
| `storage.s3.key` | | Access key ID |
| `storage.s3.secret` | ✔ | Secret access key |
| `storage.s3.region` | | e.g. `eu-west-1` |
| `storage.s3.bucket` | | Bucket name |
| `storage.s3.endpoint` | | Only for S3-compatibles (MinIO, R2, Spaces) |
| `storage.s3.path_style` | | Path-style endpoint — required by MinIO & most S3-compatibles |
| `storage.s3.url` | | Optional public/CDN base URL |
| `storage.azure.connection_string` | ✔ | Storage account → Access keys → Connection string |
| `storage.azure.container` | | Container name |
| `storage.azure.url` | | Optional — defaults to the container's blob endpoint |
| `storage.gcs.key_json` | ✔ | Full service-account key JSON |
| `storage.gcs.bucket` | | Bucket name |
| `storage.gcs.uniform_acl` | | On (default) for uniform bucket-level access; off for fine-grained ACLs |
| `storage.gcs.url` | | Optional — defaults to `https://storage.googleapis.com/<bucket>` |

Secret fields show a masked placeholder once set and are only overwritten when you type a new value — leaving them blank keeps the stored one.

**Media must be publicly readable to render**: give the S3 bucket public-read (or front it with a CDN and set the `url`), the Azure container "Blob" public access level, and the GCS bucket `allUsers` → `Storage Object Viewer` (or, again, a CDN + `url`).

Then hit **Test storage connection** (header action, visible once the driver is usable). It writes a probe file through your *just-saved* settings, reads it back, checks a public URL is produced, and deletes it — misconfigured credentials fail right there with the provider's error message.

## 3. How it works

`src/Services/MediaStorage.php` is the single gate (mirroring `SocialProviders` for SSO):

- `usable()` = a cloud driver is selected **and** credentialed **and** its adapter package is installed.
- When usable, boot registers a named disk **`pb-cloud`** in `filesystems.disks` from the stored settings (guarded — a missing adapter, un-migrated settings table or bad credentials can never break boot; media just falls back to `media.disk`).
- All three upload paths (media library, `/pb-upload`, record image fields) resolve their disk through `MediaStorage::diskName()` — so with `storage.driver = local` (the default) behaviour is exactly what it was before this feature: the host-app disk from `media.disk` / `AI_PAGE_BUILDER_MEDIA_DISK`. That remains the escape hatch for any disk the Settings UI doesn't cover.
- Each media row records the disk it lives on (`page_builder_media.disk`), and `MediaItem::url()` asks that disk for the URL — cloud/CDN hosts come back absolute, local disks root-relative.

Azure and GCS use package-registered driver names (`pb-azure`, `pb-gcs`) so they can't collide with `azure`/`gcs` disks your host app may already define.

## 4. Migrate existing media: `ai-page-builder:migrate-media`

Moves every media-library file that isn't already on the target disk, flips each row's `disk` column, and **rewrites the old URLs baked into saved content** (pages — including email templates — and partials: `html`, `css`, custom CSS/JS, and GrapesJS `project_data`).

```bash
# Always start with a dry run — full report, zero changes:
php artisan ai-page-builder:migrate-media --dry-run

# The real thing (targets the configured cloud disk by default):
php artisan ai-page-builder:migrate-media

# Free the server space once you've verified the site renders:
php artisan ai-page-builder:migrate-media --delete-source
```

| Option | Meaning |
|---|---|
| `--disk=` | Target disk name — defaults to the configured cloud disk (`pb-cloud`); any host-app disk name works too |
| `--dry-run` | Report what would be migrated/rewritten without changing anything |
| `--delete-source` | Delete each original **after** its copy verified and its row flipped |
| `--chunk=100` | Media rows per chunk |

Safety properties:

- Files are **stream-copied** (constant memory) and **verified** (existence + size) before the row flips; the source is only deleted after that.
- Content rewriting only includes files that actually made it — a failed copy never leaves a page pointing at a missing file.
- Selection is `disk != target`, so the command is **idempotent and resumable**: rerun it after a partial failure and it picks up where it left off. Per-file failures are warned and counted; the command exits non-zero if any occurred.
- It also works in reverse (cloud → local): `--disk=public` migrates everything back.

### Known limitations

- **Record image fields** (an `image` field on a collection) store bare path strings in your `pb_<key>` tables, not media-library rows — the command doesn't move those files or rewrite those columns. New record uploads go to the cloud disk automatically once configured; if you use record image fields, don't pass `--delete-source` (the originals keep serving the old records).
- **Page revisions** keep their original URLs on purpose — history should render what was saved at the time. Don't `--delete-source` if you need old revisions to keep their images.

## Local development

No cloud account needed — MinIO speaks S3:

```bash
docker run --rm -p 9000:9000 -p 9001:9001 minio/minio server /data --console-address :9001
```

Settings → Storage → Amazon S3: endpoint `http://localhost:9000`, path-style **on**, key/secret `minioadmin`, any bucket you created in the MinIO console.
