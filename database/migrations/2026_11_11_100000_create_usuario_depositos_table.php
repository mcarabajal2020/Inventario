<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Depósitos que cada usuario puede ver en Consulta de Stock.
     *
     * Tabla local, como `usuario_recursos`: el ERP no se escribe. Sin
     * filas para un usuario, ve todos los depósitos (mismo criterio que
     * el resto de los permisos: sin configuración, acceso completo).
     */
    public function up(): void
    {
        Schema::create('usuario_depositos', function (Blueprint $table) {
            $table->id();
            $table->string('sisusrcod', 10);
            $table->string('depcod', 10);
            $table->unique(['sisusrcod', 'depcod']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_depositos');
    }
};
