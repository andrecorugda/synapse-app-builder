<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Services\MediaStorage;
use Andre\AiPageBuilder\Services\MediaUrlRewriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves media-library files onto another disk — typically the configured
 * cloud disk (see MediaStorage) — and keeps everything pointing at them:
 * each migrated row's `disk` column is flipped, and the old URLs baked into
 * saved page / partial content are rewritten to the new ones.
 *
 * Selection is `disk != target`, so reruns resume where a failed or partial
 * run stopped. Originals are kept unless --delete-source is passed, and a
 * source file is only ever deleted after the copy verified and the row
 * flipped. Record-column image fields (bare path strings in pb_* tables) are
 * not media-library rows and are not migrated — see docs/cloud-storage.md.
 */
class MigrateMediaCommand extends Command
{
    protected $signature = 'ai-page-builder:migrate-media
        {--disk= : Target disk name (defaults to the configured cloud disk)}
        {--dry-run : Report what would change without changing anything}
        {--delete-source : Delete each original after a verified copy}
        {--chunk=100 : Media rows per chunk}';

    protected $description = 'Migrate media-library files to another disk (e.g. the configured S3/Azure/GCS cloud storage), '
        .'flipping each media row and rewriting the old URLs inside saved page content.';

    public function handle(MediaStorage $storage, MediaUrlRewriter $rewriter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleteSource = (bool) $this->option('delete-source');
        $chunk = max(1, (int) $this->option('chunk'));

        $target = (string) ($this->option('disk') ?? '');
        if ($target === '') {
            if (! $storage->usable()) {
                $this->error('[ai-page-builder] No target disk. '.$storage->unusableReason());
                $this->line('Configure cloud storage on the Settings → Storage tab, or pass --disk=<name>.');

                return self::FAILURE;
            }
            $target = MediaStorage::DISK;
        }

        try {
            Storage::disk($target);
        } catch (Throwable $e) {
            $this->error(sprintf('[ai-page-builder] Target disk "%s" cannot be resolved: %s', $target, $e->getMessage()));

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be changed.');
        }

        /** @var class-string<MediaItem> $model */
        $model = config('ai-page-builder.models.media', MediaItem::class);
        $query = $model::query()->where('disk', '!=', $target);

        $total = (int) $query->clone()->count();
        if ($total === 0) {
            $this->info(sprintf('[ai-page-builder] Nothing to migrate — all media is already on "%s".', $target));

            return self::SUCCESS;
        }

        $this->info(sprintf('[ai-page-builder] Migrating %d media file(s) to "%s"…', $total, $target));

        $bar = $this->output->createProgressBar($total);
        $migrated = 0;
        $failed = 0;
        /** @var array<string,string> $urlMap old URL => new URL */
        $urlMap = [];

        $query->clone()->chunkById($chunk, function ($items) use ($target, $dryRun, $deleteSource, $bar, &$migrated, &$failed, &$urlMap): void {
            foreach ($items as $item) {
                /** @var MediaItem $item */
                try {
                    $this->migrateItem($item, $target, $dryRun, $deleteSource, $urlMap);
                    $migrated++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn(sprintf('[ai-page-builder] #%d %s: %s', $item->getKey(), $item->path(), $e->getMessage()));
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Rewrite the old URLs inside saved content — only for files that
        // actually made it, so pages never point at a failed copy.
        $report = $rewriter->rewrite($urlMap, $dryRun);
        if ($report !== []) {
            $this->table(
                ['Content', 'Replacements'],
                collect($report)->map(static fn (int $count, string $label): array => [$label, $count])->values()->all(),
            );
        }

        $this->info(sprintf(
            '[ai-page-builder] %s %d file(s), %d failed, %d content replacement(s).%s',
            $dryRun ? 'Would migrate' : 'Migrated',
            $migrated,
            $failed,
            array_sum($report),
            $deleteSource && ! $dryRun ? ' Originals deleted.' : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copy one media file to the target disk, verify it, flip the row, and
     * collect its old → new URL mapping. Order matters: verify before the row
     * flips, flip before the source is deleted.
     *
     * @param  array<string,string>  $urlMap
     */
    private function migrateItem(MediaItem $item, string $target, bool $dryRun, bool $deleteSource, array &$urlMap): void
    {
        $source = $item->disk;
        $path = $item->path();

        if (! Storage::disk($source)->exists($path)) {
            throw new \RuntimeException(sprintf('missing on source disk "%s" — skipped', $source));
        }

        // Both URL forms can be baked into content: url() (root-relative for
        // local disks — what the editor stores) and the disk's raw absolute URL.
        $oldUrls = array_unique(array_filter([
            $item->url(),
            Storage::disk($source)->url($path),
        ]));

        if ($dryRun) {
            $newUrl = Storage::disk($target)->url($path);
            foreach ($oldUrls as $old) {
                $urlMap[$old] = $newUrl;
            }

            return;
        }

        // Stream the copy — constant memory, cloud-sized files welcome.
        $in = Storage::disk($source)->readStream($path);
        if ($in === null) {
            throw new \RuntimeException('source stream could not be opened');
        }

        try {
            Storage::disk($target)->writeStream($path, $in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }

        // Verify before touching the row or the source file.
        if (! Storage::disk($target)->exists($path)) {
            throw new \RuntimeException(sprintf('copy to "%s" could not be verified — file missing on target', $target));
        }

        $sourceSize = Storage::disk($source)->size($path);
        $targetSize = Storage::disk($target)->size($path);
        if ($sourceSize !== $targetSize) {
            throw new \RuntimeException(sprintf('size mismatch after copy (source %d B, target %d B)', $sourceSize, $targetSize));
        }

        $item->update(['disk' => $target]);

        $newUrl = $item->refresh()->url();
        foreach ($oldUrls as $old) {
            $urlMap[$old] = $newUrl;
        }

        if ($deleteSource) {
            Storage::disk($source)->delete($path);
        }
    }
}
