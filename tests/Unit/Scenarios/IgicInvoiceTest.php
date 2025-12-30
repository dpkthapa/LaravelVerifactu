<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test para factura con IGIC (Canarias)
 */
class IgicInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_generates_valid_invoice_with_igic()
    {
        $invoice = Invoice::factory()->create([
            'number' => 'F-2025-CAN-001',
            'issuer_name' => 'Empresa Canaria SL',
            'issuer_tax_id' => 'B76543210',
            'type' => 'F1',
            'amount' => 100.00,
            'tax' => 7.00,
            'total' => 107.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02', // IGIC
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 7.00,
            'base_amount' => 100.00,
            'tax_amount' => 7.00,
        ]);

        $breakdown = $invoice->breakdowns->first();
        $this->assertEquals('02', $breakdown->tax_type->value);
        $this->assertEquals(7.00, $breakdown->tax_rate);
    }
}
