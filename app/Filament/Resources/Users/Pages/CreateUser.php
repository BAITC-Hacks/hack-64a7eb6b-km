<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Access\SaveUser;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Создать пользователя';

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveUser::class)->handle(auth()->user(), null, $data);
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
