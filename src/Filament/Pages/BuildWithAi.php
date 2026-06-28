<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Ai\AppBuilderService;
use Andre\AiPageBuilder\Ai\BuildPlan;
use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;

/**
 * The human-in-the-loop "Build with AI" page: describe an app in natural
 * language, review the AI's validated Build Plan, then Apply it. Generation
 * goes through AppBuilderService (gateway-backed) and application through
 * BuildPlanApplier — this page is purely the UI + review/confirm gate.
 */
class BuildWithAi extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected string $view = 'ai-page-builder::filament.pages.build-with-ai';

    /** Brief textarea (bound). */
    public ?string $brief = '';

    /** Optional brand / business guidelines textarea (bound). */
    public ?string $business = '';

    /**
     * The most recent generated plan (empty until a successful generate).
     *
     * @var array<string,mixed>
     */
    public array $plan = [];

    /**
     * Validation errors for the current plan (empty = applicable).
     *
     * @var array<int,string>
     */
    public array $planErrors = [];

    /** The raw model reply, surfaced for transparency. */
    public ?string $rawReply = null;

    public static function getNavigationLabel(): string
    {
        return 'Build with AI';
    }

    public function getTitle(): string
    {
        return 'Build with AI';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        // Sort near the top of the group (resources start at navigation_sort).
        return (int) config('ai-page-builder.filament.navigation_sort', 10) - 1;
    }

    /** True when the gateway is configured and generation is possible. */
    public function aiAvailable(): bool
    {
        return app(AppBuilderService::class)->available();
    }

    /** The input form (brief + business guidelines). Discovered as `$this->form`. */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Describe your app')
                    ->compact()
                    ->schema([
                        Forms\Components\Textarea::make('brief')
                            ->label('Describe the app you want')
                            ->required()
                            ->rows(4)
                            ->disabled(fn (): bool => ! $this->aiAvailable())
                            ->helperText('e.g. "A leads app: a Leads collection with name + email, and a contact page with a form that saves to it."'),

                        Forms\Components\Textarea::make('business')
                            ->label('Brand / business guidelines')
                            ->rows(3)
                            ->disabled(fn (): bool => ! $this->aiAvailable())
                            ->helperText('Colors, tone, behaviour — applied on top of the generated structure. Optional.'),
                    ]),
            ]);
    }

    /**
     * @return array<int,Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->generateAction(),
            $this->applyAction(),
        ];
    }

    public function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Generate plan')
            ->icon('heroicon-o-sparkles')
            ->disabled(fn (): bool => ! $this->aiAvailable())
            ->action(function (): void {
                $this->form->validate();

                try {
                    $res = app(AppBuilderService::class)->generate(
                        (string) $this->brief,
                        $this->business !== null && trim($this->business) !== '' ? $this->business : null,
                    );

                    $this->plan = is_array($res['plan'] ?? null) ? $res['plan'] : [];
                    $this->planErrors = array_values(array_filter(
                        is_array($res['errors'] ?? null) ? $res['errors'] : [],
                        'is_string',
                    ));
                    $this->rawReply = isset($res['raw']) ? (string) $res['raw'] : null;

                    if ($this->plan === []) {
                        Notification::make()
                            ->warning()
                            ->title('No plan returned')
                            ->body('The model did not return a usable build plan. Try refining your brief.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Plan generated')
                        ->body('Review the build plan below, then Apply.')
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Generation failed')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public function applyAction(): Action
    {
        return Action::make('apply')
            ->label('Apply plan')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => $this->plan !== [])
            ->disabled(fn (): bool => $this->planErrors !== [])
            ->requiresConfirmation()
            ->modalHeading('Apply build plan')
            ->modalDescription('This will create the collections, flows, pages and states in your app.')
            ->modalSubmitActionLabel('Apply')
            ->action(function (): void {
                try {
                    $result = app(BuildPlanApplier::class)->apply($this->plan);

                    $created = is_array($result['created'] ?? null) ? $result['created'] : [];
                    $errors = array_values(array_filter(
                        is_array($result['errors'] ?? null) ? $result['errors'] : [],
                        'is_string',
                    ));

                    $summary = $this->summariseCreated($created);

                    $notification = Notification::make()
                        ->title('Build plan applied')
                        ->body($summary !== '' ? 'Created '.$summary.'.' : 'Nothing new was created.');

                    if ($errors !== []) {
                        $notification
                            ->warning()
                            ->body(
                                ($summary !== '' ? 'Created '.$summary.'. ' : '')
                                .count($errors).' item(s) reported issues — see the plan panel.'
                            );
                        // Keep errors visible on the page for detail.
                        $this->planErrors = $errors;
                    } else {
                        $notification->success();
                    }

                    $notification->send();

                    // Clear the plan after a clean apply.
                    if ($errors === []) {
                        $this->plan = [];
                        $this->planErrors = [];
                        $this->rawReply = null;
                    }
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Apply failed')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    /**
     * A readable, structured summary of the current plan for the review panel.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function planSummary(): array
    {
        if ($this->plan === []) {
            return [];
        }

        $build = BuildPlan::fromArray($this->plan);

        return [
            'collections' => $build->collections(),
            'states' => $build->states(),
            'functions' => $build->functions(),
            'flows' => $build->flows(),
            'pages' => $build->pages(),
        ];
    }

    /** Pretty-printed plan JSON for the transparency block. */
    public function planJson(): string
    {
        return (string) json_encode($this->plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build a "1 collection, 1 flow, 1 page" style phrase from the applier's
     * created summary.
     *
     * @param  array<string,mixed>  $created
     */
    private function summariseCreated(array $created): string
    {
        $labels = [
            'collections' => 'collection',
            'states' => 'state',
            'functions' => 'function',
            'flows' => 'flow',
            'pages' => 'page',
        ];

        $parts = [];
        foreach ($labels as $key => $singular) {
            $items = $created[$key] ?? [];
            $count = is_array($items) ? count($items) : 0;
            if ($count > 0) {
                $parts[] = $count.' '.($count === 1 ? $singular : $singular.'s');
            }
        }

        return implode(', ', $parts);
    }
}
