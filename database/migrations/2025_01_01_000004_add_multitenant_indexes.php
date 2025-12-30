<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add optimized indexes for multi-tenant queries.
 * 
 * In a multi-client context where each issuer_tax_id is a different client,
 * these are the most frequent queries:
 * 
 * 1. Get invoices from a client: WHERE issuer_tax_id = ?
 * 2. Get pending invoices from a client: WHERE issuer_tax_id = ? AND status = ?
 * 3. Get invoices by date range from a client: WHERE issuer_tax_id = ? AND date BETWEEN ? AND ?
 * 4. Search for rectified invoice: WHERE issuer_tax_id = ? AND number = ?
 * 
 * Composite indexes significantly improve the performance of these queries.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Index for queries by issuer (client)
            $table->index('issuer_tax_id', 'invoices_issuer_tax_id_index');
            
            // Composite index for "invoices from a client with status X"
            // Very used in: dashboards, reports, retries
            $table->index(['issuer_tax_id', 'date'], 'invoices_issuer_date_index');
            
            // Index for chaining search (previous invoice)
            // Chaining must search only invoices from the same issuer
            $table->index(['issuer_tax_id', 'previous_invoice_number'], 'invoices_issuer_prev_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_issuer_tax_id_index');
            $table->dropIndex('invoices_issuer_date_index');
            $table->dropIndex('invoices_issuer_prev_number_index');
        });
    }
};
