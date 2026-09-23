<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Access\DeleteUser;
use App\Actions\Access\SaveUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Редактировать пользователя';

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();
        $data['role_ids'] = $record->roles()->pluck('id')->all();
        $data['verified'] = $record->hasVerifiedEmail();
        $data['password'] = '';
        $data['permission_ids'] = $record->permissions()->pluck('id')->all();

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof User);

        return app(SaveUser::class)->handle(auth()->user(), $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Просмотр'),
            DeleteAction::make()->label('Удалить')
                ->using(fn (User $record): bool => app(DeleteUser::class)->handle(auth()->user(), $record)),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Сохранить');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Отмена');
    }
}
