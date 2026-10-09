<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencias', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('deposito_origen_cod');
            $table->string('deposito_origen_nom');
            $table->string('deposito_destino_cod');
            $table->string('deposito_destino_nom');
            $table->string('comprobante')->nullable();
            $table->string('cbtnro')->nullable();
            $table->string('estado')->default('borrador');
            $table->text('mensaje')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('transferencia_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transferencia_id')->constrained('transferencias')->cascadeOnDelete();
            $table->string('artcod');
            $table->string('artdes')->nullable();
            $table->decimal('cantidad', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencia_detalles');
        Schema::dropIfExists('transferencias');
    }
};
