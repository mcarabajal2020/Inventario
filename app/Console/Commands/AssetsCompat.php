<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AssetsCompat extends Command
{
    protected $signature = 'assets:compat';

    protected $description = 'Baja el CSS publicado (inventario + filament) a un nivel compatible con Chrome 99+';

    public function handle(): int
    {
        $script = base_path('scripts/downlevel-css.mjs');

        if (! is_file($script)) {
            $this->warn('No existe scripts/downlevel-css.mjs; se omite el downlevel de CSS.');

            return self::SUCCESS;
        }

        $archivos = array_values(array_filter([
            public_path('css/inventario.css'),
            public_path('css/filament/filament/app.css'),
        ], 'is_file'));

        if ($archivos === []) {
            $this->warn('No hay CSS publicados todavia para procesar.');

            return self::SUCCESS;
        }

        $node = $this->encontrarNode();

        if ($node === null) {
            $this->warn('Node.js no esta disponible: el CSS queda en su version moderna (oklch/color-mix).');
            $this->warn('En Chrome 99-110 la interfaz podria fallar. Instale Node en el servidor o copie los CSS desde un entorno que lo tenga.');

            return self::SUCCESS;
        }

        $proceso = new Process(array_merge($node, [$script], $archivos), base_path());
        $proceso->setTimeout(120);
        $proceso->run();

        if ($proceso->isSuccessful()) {
            $this->info('CSS compatibilizado para Chrome 99+:');
            $this->line(trim($proceso->getOutput()));

            return self::SUCCESS;
        }

        $this->warn('No se pudo bajar el CSS (queda la version moderna):');
        $this->line(trim($proceso->getErrorOutput()));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    protected function encontrarNode(): ?array
    {
        $candidatos = [
            trim((string) env('NODE_BINARY', '')),
            'node',
        ];

        foreach ($candidatos as $binario) {
            if ($binario === '') {
                continue;
            }

            try {
                $proceso = new Process([$binario, '--version'], base_path());
                $proceso->setTimeout(15);
                $proceso->run();

                if ($proceso->isSuccessful()) {
                    return [$binario];
                }
            } catch (\Throwable) {
                // Probamos el siguiente candidato.
            }
        }

        return null;
    }
}
