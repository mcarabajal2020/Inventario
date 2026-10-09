<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepciones', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('deposito_cod');
            $table->string('deposito_nom');
            $table->string('proveedor_cod');
            $table->string('proveedor_nom');
            $table->string('ptovta');
            $table->string('hoja');
            $table->string('comprobante');
            $table->string('estado')->default('borrador');
            $table->text('mensaje')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('recepcion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_id')->constrained('recepciones')->cascadeOnDelete();
            $table->string('artcod');
            $table->string('artdes')->nullable();
            $table->decimal('cantidad', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcion_detalles');
        Schema::dropIfExists('recepciones');
    }
};
