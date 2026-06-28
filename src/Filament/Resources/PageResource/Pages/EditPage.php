<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PageResource\Pages;

use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Filament\Resources\PageResource;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Support\PageDataMapper;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PageDataMapper::merge($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PageDataMapper::split($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('publish')
                ->label(fn (Page $record): string => $record->isPublished() ? 'Unpublish' : 'Publish')
                ->icon(fn (Page $record): string => $record->isPublished() ? 'heroicon-m-eye-slash' : 'heroicon-m-globe-alt')
                ->color(fn (Page $record): string => $record->isPublished() ? 'gray' : 'success')
                ->requiresConfirmation()
                ->action(function (Page $record): void {
                    $publishing = ! $record->isPublished();
                    $record->status = $publishing ? PageStatus::Published : PageStatus::Draft;
                    $record->published_at = $publishing ? now() : null;
                    $record->save();

                    Notification::make()
                        ->success()
                        ->title($publishing ? 'Page published' : 'Page unpublished')
                        ->send();
                }),
            Actions\Action::make('view_live')
                ->label('View live')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (Page $record): bool => $record->isPublished() && (bool) config('ai-page-builder.routes.render_enabled', true))
                ->url(fn (Page $record): string => url((string) config('ai-page-builder.routes.render_prefix', 'p').'/'.$record->slug), true),
            Actions\DeleteAction::make(),
        ];
    }
}
