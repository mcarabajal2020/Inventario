<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En el apartado Inventario el campo pasó de "sucursal" a "descripcion"
     * y se le agregó "deposito" (código del ERP, obligatorio en el form).
     */
    public function up(): void
    {
        Schema::table('inventarios', function (Blueprint $table) {
            $table->renameColumn('sucursal', 'descripcion');

            // Se guarda el código del depósito (1000, 3000, ...). Nullable en
            // la BD para no romper las filas existentes: el formulario lo
            // pide obligatorio al crear/editar.
            $table->string('deposito')->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('inventarios', function (Blueprint $table) {
            $table->dropColumn('deposito');

            $table->renameColumn('descripcion', 'sucursal');
        });
    }
};
