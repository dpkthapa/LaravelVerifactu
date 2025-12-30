<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add fields to store AEAT response status.
     * 
     * Includes support for "AceptadoConErrores" (Accepted with Warnings) status.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('aeat_estado_registro', 30)
                  ->nullable()
                  ->after('csv')
                  ->index()
                  ->comment('AEAT registry status: Correcto, AceptadoConErrores, Incorrecto');
            
            $table->string('aeat_codigo_error', 20)
                  ->nullable()
                  ->after('aeat_estado_registro')
                  ->comment('AEAT error code if rejected');
            
            $table->text('aeat_descripcion_error')
                  ->nullable()
                  ->after('aeat_codigo_error')
                  ->comment('AEAT error description if rejected');
            
            $table->boolean('has_aeat_warnings')
                  ->default(false)
                  ->after('aeat_descripcion_error')
                  ->index()
                  ->comment('Indicates if invoice has AEAT warnings');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['aeat_estado_registro']);
            $table->dropIndex(['has_aeat_warnings']);
            
            $table->dropColumn([
                'aeat_estado_registro',
                'aeat_codigo_error',
                'aeat_descripcion_error',
                'has_aeat_warnings',
            ]);
        });
    }
};
