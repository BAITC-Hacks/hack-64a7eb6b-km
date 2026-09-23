<?php

namespace App\Filament\Resources\AgentRuns\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AgentRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')->copyable(),
                TextEntry::make('user.email'),
                TextEntry::make('status')->badge(),
                TextEntry::make('driver'),
                TextEntry::make('provider'),
                TextEntry::make('model'),
                TextEntry::make('prompt_version'),
                TextEntry::make('tool_calls'),
                TextEntry::make('input')->columnSpanFull(),
                TextEntry::make('output')->columnSpanFull(),
                TextEntry::make('error')->columnSpanFull(),
                KeyValueEntry::make('limits'),
                KeyValueEntry::make('usage'),
                RepeatableEntry::make('events')->schema([
                    TextEntry::make('type'),
                    TextEntry::make('created_at')->dateTime(),
                ])->columns(2)->columnSpanFull(),
            ]);
    }
}
