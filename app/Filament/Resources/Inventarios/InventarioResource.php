<?php

namespace App\Filament\Resources\Inventarios;

use App\Filament\Resources\Inventarios\Pages\CreateInventario;
use App\Filament\Resources\Inventarios\Pages\EditInventario;
use App\Filament\Resources\Inventarios\Pages\ListInventarios;
use App\Filament\Resources\Inventarios\Schemas\InventarioForm;
use App\Filament\Resources\Inventarios\Tables\InventariosTable;
use App\Models\Inventario;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use App\Exports\InventarioExport;
use App\Exports\InventarioMovimientosExport;
use Maatwebsite\Excel\Facades\Excel;


class InventarioResource extends Resource
{
    protected static ?string $model = Inventario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Inventario';

    public static function form(Schema $schema): Schema
{
    return $schema
        ->components([

            Forms\Components\TextInput::make('sucursal')
                ->required(),

            Forms\Components\DateTimePicker::make('fecha_inicio')
                ->default(now())
                ->required(),

            Forms\Components\Select::make('estado')
                ->options([
                    'abierto' => 'Abierto',
                    'cerrado' => 'Cerrado',
                ])
                ->default('abierto')
                ->required(),

        ]);
}

public static function table(Table $table): Table
{
    return $table
        ->columns([

            \Filament\Tables\Columns\TextColumn::make('id')
                ->label('ID')
                ->sortable(),

            \Filament\Tables\Columns\TextColumn::make('sucursal')
                ->label('Sucursal')
                ->searchable(),

            \Filament\Tables\Columns\TextColumn::make('estado')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'abierto' => 'success',
                    'cerrado' => 'danger',
                    default => 'gray',
                }),

            \Filament\Tables\Columns\TextColumn::make('created_at')
                ->label('Fecha')
                ->dateTime('d/m/Y H:i'),

        ])
        ->defaultSort('id', 'desc')
        ->filters([

            SelectFilter::make('estado')
                ->label('Estado')
                ->options([
                    'abierto' => 'Abierto',
                    'cerrado' => 'Cerrado',
                ])
                ->default('abierto'),

        ])
        ->actions([

            EditAction::make(),
        
            Action::make('tomar')
                ->label('Tomar')
                ->icon('heroicon-o-play')
                ->color('success')
                ->hidden(fn ($record) => $record->estado === 'cerrado')
                ->url(fn ($record) =>
                    url('/admin/tomar-inventario/' . $record->id)
                ),
                Action::make('exportar')
                ->label('Exportar Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function ($record) {
            
                    return Excel::download(
                        new InventarioExport($record->id),
                        'inventario_'.$record->id.'.xlsx'
                    );
            
                }),    
                Action::make('exportar_movimientos')
                ->label('Exportar Movimientos')
                ->icon('heroicon-o-list-bullet')
                ->color('info')
                ->action(function ($record) {

                    return Excel::download(
                        new InventarioMovimientosExport($record->id),
                        'movimientos_inventario_'.$record->id.'.xlsx'
                    );

                }),    
        ]);
}

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventarios::route('/'),
            'create' => CreateInventario::route('/create'),
            'edit' => EditInventario::route('/{record}/edit'),
        ];
    }
}
