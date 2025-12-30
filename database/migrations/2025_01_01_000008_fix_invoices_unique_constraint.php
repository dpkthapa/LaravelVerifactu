<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix unique index on invoices.
 * 
 * PROBLEM:
 * The original unique index was only by `number`, which prevented
 * different issuers (CIFs) from having the same invoice number.
 * 
 * SOLUTION:
 * The unique index must be by the pair (issuer_tax_id, number) because:
 * - The same CIF CANNOT have duplicate invoice numbers
 * - Different CIFs CAN have the same invoice number
 * 
 * This is correct for a multi-tenant connector where each client
 * has their own invoice numbering.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Drop the unique index by number only
            $table->dropUnique(['number']);
            
            // Create composite unique index (issuer_tax_id + number)
            $table->unique(['issuer_tax_id', 'number'], 'invoices_issuer_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Revert: drop composite index
            $table->dropUnique('invoices_issuer_number_unique');
            
            // Restore unique index by number only
            $table->unique('number');
        });
    }
};
