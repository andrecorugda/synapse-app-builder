<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbUserResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbUserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPbUser extends EditRecord
{
    protected static string $resource = PbUserResource::class;

    /**
     * PbUser hides email and role_id from serialization, and Filament fills the
     * edit form from attributesToArray() — so put them back for the form (an
     * empty email would otherwise fail validation or blank the account).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['email'] = $this->record->getAttribute('email');
        $data['role_id'] = $this->record->getAttribute('role_id');

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
