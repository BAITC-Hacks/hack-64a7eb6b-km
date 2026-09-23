<?php

namespace App\Enums;

enum AccessPermission: string
{
    case WorkspaceView = 'workspace.view';
    case WorkspaceRunsCreate = 'workspace.runs.create';
    case WorkspaceRunsCancel = 'workspace.runs.cancel';
    case WorkspaceApprovalsResolve = 'workspace.approvals.resolve';

    public function label(): string
    {
        return match ($this) {
            self::WorkspaceView => 'Просмотр своих запусков и заметок',
            self::WorkspaceRunsCreate => 'Создание своих запусков',
            self::WorkspaceRunsCancel => 'Отмена своих запусков',
            self::WorkspaceApprovalsResolve => 'Подтверждение и отклонение предложений агента',
        };
    }

    public function group(): string
    {
        return 'Рабочее пространство';
    }

    /** @return list<self> */
    public static function workspace(): array
    {
        return self::cases();
    }
}
