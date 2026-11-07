<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\CredentialResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\CredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCredential extends EditRecord
{
    protected static string $resource = CredentialResource::class;

    /**
     * Drop the secret before the form is filled, so the decrypted value never
     * reaches the Livewire snapshot (the form field also starts empty).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['secret']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
