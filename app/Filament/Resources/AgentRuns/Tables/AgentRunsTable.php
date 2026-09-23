<?php

namespace App\Filament\Resources\AgentRuns\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgentRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Run')->limit(12)->searchable(),
                TextColumn::make('user.email')->label('Owner')->searchable(),
                TextColumn::make('input')->limit(60)->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('driver')->badge(),
                TextColumn::make('tool_calls')->numeric(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['queued' => 'Queued', 'running' => 'Running', 'succeeded' => 'Succeeded', 'failed' => 'Failed', 'cancelled' => 'Cancelled']),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
