<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add corrected amount fields for ImporteRectificacion block.
     * 
     * These fields store the original amounts from the invoice being corrected,
     * which are required by AEAT when sending corrective invoices by substitution
     * (TipoRectificativa = "S").
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Add missing optional AEAT fields if they don't exist
            if (!Schema::hasColumn('invoices', 'tax_period')) {
                $table->string('tax_period', 2)->nullable()->after('operation_date')
                    ->comment('Tax period (e.g., "01", "02", "0A")');
            }

            if (!Schema::hasColumn('invoices', 'correction_type')) {
                $table->string('correction_type', 1)->nullable()->after('tax_period')
                    ->comment('Correction type for rectificative invoices (S=Substitution, I=Difference)');
            }

            // Corrected amounts for ImporteRectificacion block
            $table->decimal('corrected_base_amount', 15, 2)->nullable()->after('rectification_amount')
                ->comment('Original base amount from corrected invoice (for TipoRectificativa = S)');
            $table->decimal('corrected_tax_amount', 15, 2)->nullable()->after('corrected_base_amount')
                ->comment('Original tax amount from corrected invoice (for TipoRectificativa = S)');
            $table->decimal('corrected_surcharge_amount', 15, 2)->nullable()->after('corrected_tax_amount')
                ->comment('Original surcharge amount from corrected invoice (optional)');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'corrected_base_amount',
                'corrected_tax_amount',
                'corrected_surcharge_amount',
            ]);

            // Only drop these if they were added by this migration
            // (they might have existed before)
            if (Schema::hasColumn('invoices', 'correction_type')) {
                $table->dropColumn('correction_type');
            }
            if (Schema::hasColumn('invoices', 'tax_period')) {
                $table->dropColumn('tax_period');
            }
        });
    }
};
