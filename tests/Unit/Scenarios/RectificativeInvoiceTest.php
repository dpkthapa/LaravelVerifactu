<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test para facturas rectificativas (R1-R5)
 */
class RectificativeInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_rectificative_invoice_by_difference()
    {
        $originalInvoice = Invoice::factory()->create([
            'number' => 'F-2025-100',
            'type' => 'F1',
            'amount' => 100.00,
            'tax' => 21.00,
            'total' => 121.00,
        ]);

        $rectificative = Invoice::factory()->create([
            'number' => 'F-2025-100-R1',
            'type' => 'R1',
            'rectificative_type' => 'I',
            'rectified_invoices' => [
                [
                    'issuer_tax_id' => $originalInvoice->issuer_tax_id,
                    'number' => $originalInvoice->number,
                    'date' => $originalInvoice->date->format('d-m-Y'),
                ]
            ],
            'rectification_amount' => [
                'base' => -50.00,
                'tax' => -10.50,
                'total' => -60.50,
            ],
            'amount' => -50.00,
            'tax' => -10.50,
            'total' => -60.50,
        ]);

        $this->assertEquals('R1', $rectificative->type->value);
        $this->assertEquals('I', $rectificative->rectificative_type);
        $this->assertEquals(-60.50, $rectificative->total);
    }

    /** @test */
    public function it_creates_rectificative_invoice_by_substitution()
    {
        $rectificative = Invoice::factory()->create([
            'number' => 'F-2025-200-R1',
            'type' => 'R1',
            'rectificative_type' => 'S',
            'rectified_invoices' => [
                [
                    'issuer_tax_id' => 'B12345678',
                    'number' => 'F-2025-200',
                    'date' => '01-11-2025',
                ]
            ],
            'amount' => 150.00,
            'tax' => 31.50,
            'total' => 181.50,
        ]);

        $this->assertEquals('S', $rectificative->rectificative_type);
        $this->assertNull($rectificative->rectification_amount);
    }
}
