<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('inventario_movimientos', function (Blueprint $table) {

        $table->id();

        $table->foreignId('inventario_id');

        $table->string('artcod');

        $table->string('codigo_barra')->nullable();

        $table->decimal('cantidad', 12, 2);

        $table->string('ubicacion')->nullable();

        $table->string('usuario');

        $table->timestamp('created_at');

        $table->index('inventario_id');

        $table->index('artcod');

    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventario_movimientos');
    }
};
