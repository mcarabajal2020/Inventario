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
    Schema::create('inventarios', function (Blueprint $table) {
        $table->id();

        $table->string('sucursal');

        $table->dateTime('fecha_inicio');

        $table->dateTime('fecha_fin')->nullable();

        $table->string('estado')->default('abierto');

        $table->string('user_id');

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventarios');
    }
};
