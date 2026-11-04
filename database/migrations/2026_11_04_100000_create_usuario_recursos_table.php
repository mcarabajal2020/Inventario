<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permisos de recursos por usuario. Los usuarios viven en el ERP
     * (`siserpy.sisusuar`), pero esta tabla es local: no se escribe en
     * ninguna base del ERP.
     *
     * Sin fila para un (usuario, recurso) el recurso está habilitado, así
     * que el alta masiva de usuarios del ERP no necesita configuración.
     */
    public function up(): void
    {
        Schema::create('usuario_recursos', function (Blueprint $table) {
            $table->id();
            $table->string('sisusrcod', 10);
            $table->string('recurso', 50);
            $table->boolean('habilitado')->default(true);
            $table->unique(['sisusrcod', 'recurso']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_recursos');
    }
};
