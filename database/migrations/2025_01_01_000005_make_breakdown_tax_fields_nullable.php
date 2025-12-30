<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make tax_rate and tax_amount nullable in breakdowns.
 * 
 * REASON:
 * For N1/N2 (not subject) and E1-E6 (exempt) operations,
 * AEAT does NOT allow TipoImpositivo or CuotaRepercutida to be reported.
 * These fields must be NULL in the DB for these operations.
 * 
 * @see AeatClient.php - logic for omitting fields
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('breakdowns', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 2)->nullable()->change();
            $table->decimal('tax_amount', 15, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('breakdowns', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 2)->nullable(false)->change();
            $table->decimal('tax_amount', 15, 2)->nullable(false)->change();
        });
    }
};
