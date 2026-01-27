<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;

/**
 * Test for ImporteRectificacion block in AeatClient
 */
class ImporteRectificacionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_builds_importe_rectificacion_block_for_substitution_correctives()
    {
        // Create a corrective invoice by substitution with corrected amounts
        $invoice = Invoice::factory()->create([
            'number' => 'F-2025-300-R1',
            'type' => 'R1',
            'correction_type' => 'S',
            'rectificative_type' => 'S',
            'corrected_base_amount' => 100.00,
            'corrected_tax_amount' => 21.00,
            'corrected_surcharge_amount' => 5.20,
            'amount' => 150.00,
            'tax' => 31.50,
            'total' => 181.50,
        ]);

        // Create breakdown
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 150.00,
            'tax_amount' => 31.50,
        ]);

        // Use reflection to access private method
        $client = new AeatClient(
            certPath: '/tmp/test.pem',
            certPassword: null,
            production: false
        );

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('buildImporteRectificacion');
        $method->setAccessible(true);

        $result = $method->invoke($client, $invoice);

        // Assert the block structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('BaseRectificada', $result);
        $this->assertArrayHasKey('CuotaRectificada', $result);
        $this->assertArrayHasKey('CuotaRecargoRectificado', $result);

        $this->assertEquals('100.00', $result['BaseRectificada']);
        $this->assertEquals('21.00', $result['CuotaRectificada']);
        $this->assertEquals('5.20', $result['CuotaRecargoRectificado']);
    }

    /** @test */
    public function it_returns_null_when_corrected_amounts_are_missing()
    {
        // Create invoice without corrected amounts
        $invoice = Invoice::factory()->create([
            'number' => 'F-2025-400-R1',
            'type' => 'R1',
            'correction_type' => 'S',
            'corrected_base_amount' => null,
            'corrected_tax_amount' => null,
        ]);

        $client = new AeatClient(
            certPath: '/tmp/test.pem',
            certPassword: null,
            production: false
        );

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('buildImporteRectificacion');
        $method->setAccessible(true);

        $result = $method->invoke($client, $invoice);

        $this->assertNull($result);
    }

    /** @test */
    public function it_omits_surcharge_when_null()
    {
        // Create invoice with corrected amounts but no surcharge
        $invoice = Invoice::factory()->create([
            'number' => 'F-2025-500-R1',
            'type' => 'R1',
            'correction_type' => 'S',
            'corrected_base_amount' => 100.00,
            'corrected_tax_amount' => 21.00,
            'corrected_surcharge_amount' => null,
        ]);

        $client = new AeatClient(
            certPath: '/tmp/test.pem',
            certPassword: null,
            production: false
        );

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('buildImporteRectificacion');
        $method->setAccessible(true);

        $result = $method->invoke($client, $invoice);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('BaseRectificada', $result);
        $this->assertArrayHasKey('CuotaRectificada', $result);
        $this->assertArrayNotHasKey('CuotaRecargoRectificado', $result);
    }

    /** @test */
    public function it_identifies_corrective_invoice_types()
    {
        $client = new AeatClient(
            certPath: '/tmp/test.pem',
            certPassword: null,
            production: false
        );

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('isCorrectiveInvoice');
        $method->setAccessible(true);

        // Test corrective types
        $this->assertTrue($method->invoke($client, 'R1'));
        $this->assertTrue($method->invoke($client, 'R2'));
        $this->assertTrue($method->invoke($client, 'R3'));
        $this->assertTrue($method->invoke($client, 'R4'));
        $this->assertTrue($method->invoke($client, 'R5'));

        // Test non-corrective types
        $this->assertFalse($method->invoke($client, 'F1'));
        $this->assertFalse($method->invoke($client, 'F2'));
        $this->assertFalse($method->invoke($client, 'F3'));
    }
}
