<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\Access\DeleteRole;
use App\Actions\Access\SaveRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'Редактировать роль';

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $record */
        $record = $this->getRecord();

        $data['permission_ids'] = $record->permissions()->pluck('id')->all();

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Role);

        return app(SaveRole::class)->handle(auth()->user(), $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Просмотр'),
            DeleteAction::make()->label('Удалить')
                ->using(fn (Role $record): bool => app(DeleteRole::class)->handle(auth()->user(), $record)),
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
