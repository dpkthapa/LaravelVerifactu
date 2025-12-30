<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test para facturas simplificadas (F2)
 */
class SimplifiedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_valid_simplified_invoice_without_recipient()
    {
        $invoice = Invoice::factory()->create([
            'number' => 'TICKET-2025-001',
            'date' => now(),
            'issuer_name' => 'Retail Store SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F2',
            'amount' => 50.00,
            'tax' => 10.50,
            'total' => 60.50,
            'is_first_invoice' => false,
            'customer_name' => null,
            'customer_tax_id' => null,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 50.00,
            'tax_amount' => 10.50,
        ]);

        $this->assertNull($invoice->customer_name);
        $this->assertEquals('F2', $invoice->type);
    }

    /** @test */
    public function simplified_invoice_can_be_chained()
    {
        $firstInvoice = Invoice::factory()->create([
            'number' => 'TICKET-100',
            'type' => 'F2',
            'is_first_invoice' => true,
            'amount' => 30.00,
            'tax' => 6.30,
            'total' => 36.30,
        ]);

        $secondInvoice = Invoice::factory()->create([
            'number' => 'TICKET-101',
            'type' => 'F2',
            'is_first_invoice' => false,
            'previous_invoice_number' => $firstInvoice->number,
            'previous_invoice_date' => $firstInvoice->date,
            'previous_invoice_hash' => $firstInvoice->hash,
            'amount' => 45.00,
            'tax' => 9.45,
            'total' => 54.45,
        ]);

        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
    }
}
