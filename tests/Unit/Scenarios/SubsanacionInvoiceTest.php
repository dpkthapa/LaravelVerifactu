<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test para facturas de subsanación
 */
class SubsanacionInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_subsanacion_invoice_after_rejection()
    {
        $rejectedInvoice = Invoice::factory()->create([
            'number' => 'F-2025-100-REJ',
            'type' => 'F1',
            'status' => 'rejected',
            'amount' => 100.00,
            'tax' => 21.00,
            'total' => 121.00,
        ]);

        $subsanacion = Invoice::factory()->create([
            'number' => 'F-2025-100',
            'type' => 'F1',
            'is_subsanacion' => true,
            'rejected_invoice_number' => $rejectedInvoice->number,
            'rejection_date' => $rejectedInvoice->date,
            'amount' => 100.00,
            'tax' => 21.00,
            'total' => 121.00,
        ]);

        $this->assertTrue($subsanacion->is_subsanacion);
        $this->assertEquals($rejectedInvoice->number, $subsanacion->rejected_invoice_number);
    }
}
