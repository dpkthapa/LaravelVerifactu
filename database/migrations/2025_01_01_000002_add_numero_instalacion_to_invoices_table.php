<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add installation number field for multi-tenant support.
     * 
     * Each client/installation must have a unique installation number.
     * Format example: CIF-001
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('numero_instalacion', 100)
                  ->nullable()
                  ->after('issuer_tax_id')
                  ->comment('Unique installation number for VERIFACTU (max 100 chars per AEAT XSD). Format: CIF-001');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('numero_instalacion');
        });
    }
};
