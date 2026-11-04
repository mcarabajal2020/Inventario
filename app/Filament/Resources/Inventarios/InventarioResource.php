<?php

namespace App\Filament\Resources\Inventarios;

use App\Filament\Resources\Inventarios\Pages\CreateInventario;
use App\Filament\Resources\Inventarios\Pages\EditInventario;
use App\Filament\Resources\Inventarios\Pages\ListInventarios;
use App\Filament\Resources\Inventarios\Schemas\InventarioForm;
use App\Filament\Resources\Inventarios\Tables\InventariosTable;
use App\Models\Inventario;
use App\Services\ControlAcceso;
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
use App\Services\SisApiClient;
use Exception;
use Maatwebsite\Excel\Facades\Excel;


class InventarioResource extends Resource
{
    protected static ?string $model = Inventario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && ControlAcceso::habilitado(ControlAcceso::usuarioActual(), 'inventarios');
    }

    protected static ?string $recordTitleAttribute = 'Inventario';

    public static function form(Schema $schema): Schema
{
    return $schema
        ->components([

            Forms\Components\TextInput::make('descripcion')
                ->label('Descripción')
                ->required(),

            Forms\Components\Select::make('deposito')
                ->label('Depósito')
                ->options(fn (): array => static::opcionesDepositos())
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

            \Filament\Tables\Columns\TextColumn::make('descripcion')
                ->label('Descripción')
                ->searchable(),

            \Filament\Tables\Columns\TextColumn::make('deposito')
                ->label('Depósito')
                ->formatStateUsing(fn ($state) => static::nombreDeposito((string) $state))
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
                Action::make('diferencias')
                ->label('Control de diferencias')
                ->icon('heroicon-o-scale')
                ->color('warning')
                ->hidden(fn () => ! ControlAcceso::habilitado(
                    ControlAcceso::usuarioActual(),
                    'control-diferencias'
                ))
                ->url(fn ($record) =>
                    url('/admin/control-diferencias?inventario=' . $record->id)
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

    /**
     * Opciones del select de depósitos: código (depcod) => nombre (depnom),
     * tomadas del ERP y cacheadas por SisApiClient.
     *
     * Si el ERP no responde (o falta la configuración) se devuelve una lista
     * vacía: el select queda sin opciones y la validación "requerido" avisa en
     * lugar de romper el formulario o el listado.
     */
    public static function opcionesDepositos(): array
    {
        try {
            $depositos = app(SisApiClient::class)->depositos();
        } catch (Exception) {
            return [];
        }

        $opciones = [];

        foreach ($depositos as $deposito) {
            $depcod = (string) ($deposito['depcod'] ?? '');
            $depnom = (string) ($deposito['depnom'] ?? '');

            if ($depcod !== '') {
                $opciones[$depcod] = $depnom !== '' ? $depnom : $depcod;
            }
        }

        return $opciones;
    }

    /** Nombre del depósito para mostrar en el listado (fallback: el código). */
    public static function nombreDeposito(string $depcod): string
    {
        if ($depcod === '') {
            return '';
        }

        return static::opcionesDepositos()[$depcod] ?? $depcod;
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
