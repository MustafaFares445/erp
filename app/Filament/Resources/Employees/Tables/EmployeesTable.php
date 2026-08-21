<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Tables;

use App\Models\EmployeeProfile;
use App\Services\Employees\EmployeeAccessService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

final class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->contentGrid([
                'sm' => 1,
                'md' => 2,
                'xl' => 4,
            ])
            ->columns([
                Split::make([
                    IconColumn::make('avatar_placeholder')
                        ->label('')
                        ->getStateUsing(static fn (EmployeeProfile $record): bool => $record->is_active)
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->size('lg')
                        ->color(static fn (bool $state): string => $state ? 'primary' : 'gray')
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('user.name')
                            ->label('Account name')
                            ->searchable()
                            ->sortable()
                            ->weight(FontWeight::Bold),
                        TextColumn::make('job_title')
                            ->label('')
                            ->searchable()
                            ->sortable()
                            ->icon(Heroicon::OutlinedBriefcase)
                            ->color('gray'),
                    ]),
                ]),
                TextColumn::make('email')
                    ->label('')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('')
                    ->icon(Heroicon::OutlinedPhone)
                    ->searchable(),
                Stack::make([
                    TextColumn::make('employee_code')
                        ->label('Employee code')
                        ->badge()
                        ->color('gray')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('job_title_badge')
                        ->label('Job title')
                        ->getStateUsing(static fn (EmployeeProfile $record): string => $record->job_title)
                        ->badge()
                        ->color('info'),
                    TextColumn::make('status_badge')
                        ->label('Status')
                        ->getStateUsing(static fn (EmployeeProfile $record): bool => $record->is_active)
                        ->formatStateUsing(static fn (bool $state): string => $state ? 'Active' : 'Inactive')
                        ->badge()
                        ->color(static fn (bool $state): string => $state ? 'success' : 'danger'),
                ])->extraAttributes(['class' => 'flex flex-wrap gap-1']),
            ])
            ->groups([
                Group::make('job_title')->label('Job title'),
                Group::make('is_active')
                    ->label('Status')
                    ->getTitleFromRecordUsing(static fn (EmployeeProfile $record): string => $record->is_active ? 'Active' : 'Inactive'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('job_title')->options(
                    static fn (): array => EmployeeProfile::query()
                        ->distinct()
                        ->orderBy('job_title')
                        ->pluck('job_title', 'job_title')
                        ->all(),
                ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    Action::make('disable')
                        ->label('Disable access')
                        ->icon(Heroicon::OutlinedLockClosed)
                        ->requiresConfirmation()
                        ->visible(static fn (EmployeeProfile $record): bool => $record->is_active)
                        ->authorize('update')
                        ->action(static fn (EmployeeProfile $record) => app(EmployeeAccessService::class)->disable($record)),
                    Action::make('enable')
                        ->label('Enable access')
                        ->icon(Heroicon::OutlinedLockOpen)
                        ->requiresConfirmation()
                        ->visible(static fn (EmployeeProfile $record): bool => ! $record->is_active)
                        ->authorize('update')
                        ->action(static fn (EmployeeProfile $record) => app(EmployeeAccessService::class)->enable($record)),
                    Action::make('archive')
                        ->label('Delete')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('delete')
                        ->visible(static fn (EmployeeProfile $record): bool => ! $record->trashed())
                        ->action(static fn (EmployeeProfile $record) => app(EmployeeAccessService::class)->archive($record)),
                    Action::make('restore')
                        ->label('Restore')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->requiresConfirmation()
                        ->authorize('restore')
                        ->visible(static fn (EmployeeProfile $record): bool => $record->trashed())
                        ->action(static fn (EmployeeProfile $record) => app(EmployeeAccessService::class)->restore($record)),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Delete selected')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('deleteAny')
                        ->action(static function (Collection $records): void {
                            $service = app(EmployeeAccessService::class);

                            /** @var EmployeeProfile $record */
                            foreach ($records as $record) {
                                $service->archive($record);
                            }
                        }),
                    BulkAction::make('restore')
                        ->label('Restore selected')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->requiresConfirmation()
                        ->authorize('restoreAny')
                        ->action(static function (Collection $records): void {
                            $service = app(EmployeeAccessService::class);

                            /** @var EmployeeProfile $record */
                            foreach ($records as $record) {
                                $service->restore($record);
                            }
                        }),
                ]),
            ]);
    }
}
