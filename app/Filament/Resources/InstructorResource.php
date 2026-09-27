<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorResource\Pages;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutItemsRelationManager;
use App\Models\Instructor;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only by design (see the challenge brief): instructor balance + payout history.
 * No create/edit/delete — instructors, courses and subscriptions are created by the
 * product's normal signup/enrolment flow, never by an admin typing them into a form.
 */
class InstructorResource extends Resource
{
    protected static ?string $model = Instructor::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Instructor Balances';

    protected static ?string $modelLabel = 'instructor balance';

    public static function table(Table $table): Table
    {
        return $table
            ->query(Instructor::query()->with(['user', 'balance']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('balance.cached_earned_cents')
                    ->label('Earned to date')
                    ->formatStateUsing(fn (?int $state) => self::money($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance.paid_cents')
                    ->label('Paid out')
                    ->formatStateUsing(fn (?int $state) => self::money($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance.pending_cents')
                    ->label('Pending payout')
                    ->formatStateUsing(fn (?int $state) => self::money($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->state(fn (Instructor $record) => ($record->balance?->cached_earned_cents ?? 0)
                        - ($record->balance?->paid_cents ?? 0)
                        - ($record->balance?->pending_cents ?? 0))
                    ->formatStateUsing(fn (int $state) => self::money($state))
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('balance.cached_at')
                    ->label('Figures as of')
                    ->since()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('id');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Instructor')
                ->columns(2)
                ->schema([
                    TextEntry::make('user.name')->label('Name'),
                    TextEntry::make('user.email')->label('Email'),
                    TextEntry::make('payout_account_reference')->label('Payout account'),
                ]),
            Section::make('Balance')
                ->columns(4)
                ->schema([
                    TextEntry::make('balance.cached_earned_cents')
                        ->label('Earned to date')
                        ->formatStateUsing(fn (?int $state) => self::money($state)),
                    TextEntry::make('balance.paid_cents')
                        ->label('Paid out')
                        ->formatStateUsing(fn (?int $state) => self::money($state)),
                    TextEntry::make('balance.pending_cents')
                        ->label('Pending payout')
                        ->formatStateUsing(fn (?int $state) => self::money($state)),
                    TextEntry::make('balance.cached_at')
                        ->label('Figures as of')
                        ->since(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            PayoutItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function money(?int $cents): string
    {
        return number_format(($cents ?? 0) / 100, 2).' EGP';
    }
}
