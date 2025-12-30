<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add VeriFactu fields to invoices table.
     * 
     * Adds support for:
     * - CSV code from AEAT
     * - Invoice chaining (blockchain)
     * - Rectificative invoices
     * - Subsanación (resubmission after rejection)
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // CSV from AEAT
            $table->string('csv', 16)->nullable()->index()->after('hash');
            
            // Invoice chaining (blockchain)
            $table->string('previous_invoice_number', 60)->nullable()->after('csv');
            $table->date('previous_invoice_date')->nullable()->after('previous_invoice_number');
            $table->string('previous_invoice_hash', 64)->nullable()->after('previous_invoice_date');
            $table->boolean('is_first_invoice')->default(true)->after('previous_invoice_hash');
            
            // Rectificative invoices
            $table->string('rectificative_type', 1)->nullable()->after('is_first_invoice')
                ->comment('I=By difference, S=By substitution');
            $table->json('rectified_invoices')->nullable()->after('rectificative_type')
                ->comment('Array of rectified invoice references');
            $table->json('rectification_amount')->nullable()->after('rectified_invoices')
                ->comment('Rectification amounts (base, tax, total)');
            
            // Optional AEAT fields
            $table->date('operation_date')->nullable()->after('rectification_amount')
                ->comment('Operation date if different from issue date');
            $table->boolean('is_subsanacion')->default(false)->after('operation_date')
                ->comment('Resubmission after AEAT rejection');
            $table->string('rejected_invoice_number', 60)->nullable()->after('is_subsanacion')
                ->comment('Original rejected invoice number');
            $table->date('rejection_date')->nullable()->after('rejected_invoice_number')
                ->comment('Date of rejection by AEAT');
            
            // Indexes for performance
            $table->index('previous_invoice_number');
            $table->index('is_first_invoice');
            $table->index('rectificative_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['previous_invoice_number']);
            $table->dropIndex(['is_first_invoice']);
            $table->dropIndex(['rectificative_type']);
            $table->dropIndex(['csv']);
            
            $table->dropColumn([
                'csv',
                'previous_invoice_number',
                'previous_invoice_date',
                'previous_invoice_hash',
                'is_first_invoice',
                'rectificative_type',
                'rectified_invoices',
                'rectification_amount',
                'operation_date',
                'is_subsanacion',
                'rejected_invoice_number',
                'rejection_date',
            ]);
        });
    }
};
