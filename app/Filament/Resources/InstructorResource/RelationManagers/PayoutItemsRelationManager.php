<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Filament\Resources\InstructorResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PayoutItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'payoutItems';

    protected static ?string $title = 'Payout history';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('idempotency_key')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state) => InstructorResource::money($state)),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        \App\Enums\PayoutItemStatus::Succeeded => 'success',
                        \App\Enums\PayoutItemStatus::Failed => 'danger',
                        \App\Enums\PayoutItemStatus::Processing => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('provider_reference')
                    ->label('Provider ref')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failure_reason')
                    ->label('Failure reason')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('requires_manual_review')
                    ->boolean()
                    ->label('Needs review'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Claimed at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
