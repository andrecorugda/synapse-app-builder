<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Filament\Forms\Components\CodeField;
use Andre\AiPageBuilder\Filament\Forms\Components\GrapesJsField;
use Andre\AiPageBuilder\Filament\Resources\PageResource\Pages;
use Andre\AiPageBuilder\Models\Page;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.page', Page::class);
    }

    public static function getModelLabel(): string
    {
        return 'page';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Page details')
                    ->compact()
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(200)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Set $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        Forms\Components\Select::make('status')
                            ->options(collect(PageStatus::cases())->mapWithKeys(fn (PageStatus $s) => [$s->value => $s->label()])->all())
                            ->default(PageStatus::Draft->value)
                            ->required(),
                        Forms\Components\Select::make('kind')
                            ->label('Type')
                            ->options([
                                'page' => 'Page',
                                'email' => 'Email template',
                            ])
                            ->default('page')
                            ->required()
                            ->native(false)
                            ->helperText('Email templates are used as the HTML body of emails sent from flows.'),
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(200)
                            ->regex('/^[a-z0-9\-_]+$/')
                            ->unique(ignoreRecord: true)
                            ->helperText('Lowercase letters, numbers, dashes — the page URL.'),
                    ]),

                GrapesJsField::make('builder')
                    ->label('Page content')
                    // Must stay dehydrated (included in form state) so the page
                    // mapper can split it into the project_data/html/css columns;
                    // the mapper unsets 'builder' before the model is saved.
                    ->default(['project_data' => [], 'html' => '', 'css' => ''])
                    ->columnSpanFull(),

                Schemas\Components\Section::make('SEO')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('meta.title')->label('Meta title')->maxLength(200),
                        Forms\Components\Textarea::make('meta.description')->label('Meta description')->rows(2)->maxLength(300),
                        Forms\Components\TextInput::make('meta.og_image')->label('OG image URL')->url(),
                        Forms\Components\TextInput::make('meta.canonical')->label('Canonical URL')->url(),
                        Forms\Components\Toggle::make('meta.noindex')->label('Discourage search engines (noindex)'),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make('Advanced')
                    ->collapsed()
                    ->schema([
                        CodeField::make('custom_css')
                            ->label('Custom CSS')
                            ->language('css')
                            ->height(240)
                            ->helperText('Raw CSS appended to this page. Target your element classes, e.g. .pb-hero__title { letter-spacing: -0.02em; }')
                            ->columnSpanFull(),

                        CodeField::make('custom_js')
                            ->label('Custom JavaScript')
                            ->language('javascript')
                            ->height(240)
                            ->helperText('Raw JS injected before </body>, after the page renders — an escape hatch for unhandled scenarios. Runs on your own site; no sandboxing.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('slug')->searchable()->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PageStatus $state): string => $state->label())
                    ->color(fn (PageStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'email' ? 'Email template' : 'Page')
                    ->color(fn (string $state): string => $state === 'email' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(PageStatus::cases())->mapWithKeys(fn (PageStatus $s) => [$s->value => $s->label()])->all()),
                Tables\Filters\SelectFilter::make('kind')
                    ->label('Type')
                    ->options([
                        'page' => 'Page',
                        'email' => 'Email template',
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\ReplicateAction::make()
                    ->label('Duplicate')
                    ->icon('heroicon-m-document-duplicate')
                    ->color('gray')
                    ->beforeReplicaSaved(function (Page $replica): void {
                        $replica->title = $replica->title.' (Copy)';
                        $replica->slug = Str::slug($replica->title).'-'.Str::lower(Str::random(5));
                        $replica->status = PageStatus::Draft;
                        $replica->published_at = null;
                    }),
                Actions\Action::make('view_live')
                    ->label('View live')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Page $record): bool => $record->isPublished() && (bool) config('ai-page-builder.routes.render_enabled', true))
                    ->url(fn (Page $record): string => url((string) config('ai-page-builder.routes.render_prefix', 'p').'/'.$record->slug), true),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
