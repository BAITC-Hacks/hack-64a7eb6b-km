<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\Access\SaveRole;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'Создать роль';

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveRole::class)->handle(auth()->user(), null, $data);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Создать');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label('Создать и добавить ещё');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Отмена');
    }
}
