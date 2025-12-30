<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add id_type field for foreign recipients per AEAT requirements.
     * 
     * Possible values:
     * - 02: NIF-IVA
     * - 03: Passport
     * - 04: Official identification document issued by the country
     * - 05: Certificate of fiscal residence
     * - 06: Other supporting document
     * - 07: Not registered (not registered in Spain)
     */
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('id_type', 2)->nullable()->after('country')
                ->comment('ID type for foreign recipients: 02=NIF-IVA, 03=Passport, 04=Official doc, 05=Tax residence cert, 06=Other, 07=Not registered');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn('id_type');
        });
    }
};
