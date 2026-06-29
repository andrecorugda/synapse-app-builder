<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Services\AppExporter;
use Andre\AiPageBuilder\Services\AppImporter;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * App portability: download the whole app as one JSON document, or import a
 * previously-exported one. Both ends ride the plan shape — export emits it,
 * import replays it through the BuildPlanApplier — so an app moves between
 * environments (dev → prod, or app → app) losslessly and idempotently.
 */
class AppPortability extends FilamentPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected string $view = 'ai-page-builder::filament.pages.app-portability';

    public static function getNavigationLabel(): string
    {
        return 'Export / Import';
    }

    public function getTitle(): string
    {
        return 'App Export / Import';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.developer', 'Developer');
    }

    public static function getNavigationSort(): ?int
    {
        // Just below the Settings page (which sits at sort + 100).
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 110;
    }

    /**
     * @return array<int,Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download export')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->downloadExport()),

            Action::make('import')
                ->label('Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalSubmitActionLabel('Import')
                ->schema([
                    Forms\Components\Textarea::make('json')
                        ->label('Exported JSON')
                        ->helperText('Paste the JSON from a previous export. Existing collections, pages, flows, etc. are matched by key/slug and updated in place.')
                        ->rows(14)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->runImport((string) ($data['json'] ?? ''));
                }),
        ];
    }

    /**
     * Stream the export as a downloadable JSON file.
     */
    private function downloadExport(): StreamedResponse
    {
        $plan = app(AppExporter::class)->export();
        $json = (string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $filename = 'synapse-app-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(
            function () use ($json): void {
                echo $json;
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Parse the pasted JSON and replay it through the importer, surfacing the
     * summary counts (and any per-item issues) as a notification.
     */
    private function runImport(string $json): void
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            Notification::make()->danger()->title('Invalid JSON')->body('Could not parse the pasted document.')->send();

            return;
        }

        try {
            /** @var array<string,mixed> $decoded */
            $summary = app(AppImporter::class)->import($decoded);
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Import failed')->body($e->getMessage())->send();

            return;
        }

        $counts = [];
        foreach ($summary['created'] as $section => $items) {
            if ($items !== []) {
                $counts[] = count($items).' '.$section;
            }
        }

        $body = $counts === [] ? 'Nothing to import.' : 'Imported: '.implode(', ', $counts).'.';
        if ($summary['errors'] !== []) {
            $body .= ' '.count($summary['errors']).' issue(s) — see logs.';
        }

        $notification = Notification::make()->title('Import complete')->body($body);
        $summary['errors'] === [] ? $notification->success() : $notification->warning();
        $notification->send();
    }
}
