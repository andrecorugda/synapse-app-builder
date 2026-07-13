<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\MediaResource\Pages;
use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Services\MediaLibrary;
use Andre\AiPageBuilder\Services\MediaStorage;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;

class MediaResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.media', MediaItem::class);
    }

    public static function getModelLabel(): string
    {
        return 'media';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Media';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.content', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 1;
    }

    public static function form(Schema $schema): Schema
    {
        // Editing is limited to metadata; uploads happen via the header action.
        return $schema->components([
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\TextInput::make('alt')->label('Alt text')->maxLength(255),
            Forms\Components\TextInput::make('url')
                ->label('URL')
                ->readOnly()
                ->dehydrated(false)
                ->helperText('Click to copy.')
                ->extraInputAttributes(['data-ai-pb-copy' => 'input', 'style' => 'cursor:pointer']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                // Render the <img> ourselves with the model's port-safe relative URL.
                // Filament's ImageColumn rebuilds the src from the disk's APP_URL
                // config, which is frequently the wrong host/port in dev (-> 404).
                Tables\Columns\TextColumn::make('preview')
                    ->label('')
                    ->html()
                    ->state(fn (MediaItem $record): HtmlString => new HtmlString(
                        '<img src="'.e($record->url()).'" alt="" style="height:48px;width:64px;object-fit:cover;border-radius:0.5rem;display:block;">'
                    )),
                Tables\Columns\TextColumn::make('name')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->state(fn (MediaItem $record): string => $record->url())
                    ->copyable()
                    ->copyMessage('URL copied')
                    ->limit(34)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('mime_type')->label('Type')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('size')
                    ->formatStateUsing(fn (?int $state): string => $state ? number_format($state / 1024, 0).' KB' : '—'),
            ])
            ->headerActions([
                Actions\Action::make('upload')
                    ->label('Upload media')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->schema([
                        Forms\Components\FileUpload::make('files')
                            ->label('Files')
                            ->multiple()
                            ->image()
                            ->disk(fn (): string => app(MediaStorage::class)->diskName())
                            ->directory((string) config('ai-page-builder.media.directory', 'page-builder'))
                            ->maxSize((int) config('ai-page-builder.media.max_kb', 8192))
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $library = app(MediaLibrary::class);
                        $userId = auth()->id();
                        $userId = is_int($userId) ? $userId : (is_numeric($userId) ? (int) $userId : null);

                        foreach ((array) ($data['files'] ?? []) as $file) {
                            if ($file instanceof UploadedFile) {
                                $library->store($file, $userId);
                            }
                        }
                    }),
            ])
            ->recordActions([
                Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->url(fn (MediaItem $record): string => $record->url(), true),
                Actions\EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, MediaItem $record): array {
                        $data['url'] = $record->url();

                        return $data;
                    }),
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
            'index' => Pages\ListMedia::route('/'),
        ];
    }
}
