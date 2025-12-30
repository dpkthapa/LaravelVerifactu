<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Helpers\QrUrlHelper;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QrUrlHelperTest extends TestCase
{
    use RefreshDatabase;

    public function testBuildQrUrlInProductionVerifactuMode(): void
    {
        config(['verifactu.verifactu_mode' => true]);

        $invoice = Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => 'FAC-001',
            'date' => \Carbon\Carbon::parse('2024-01-15'),
            'customer_name' => 'Test Customer',
            'customer_tax_id' => '12345678A',
            'issuer_name' => 'Test Company',
            'issuer_tax_id' => 'B12345678',
            'amount' => 100.00,
            'tax' => 21.00,
            'total' => 121.00,
            'type' => InvoiceType::STANDARD,
        ]);

        $url = QrUrlHelper::build($invoice, 'B12345678', true, true);

        $this->assertStringContainsString('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR', $url);
        $this->assertStringContainsString('nif=B12345678', $url);
        $this->assertStringContainsString('numserie=FAC-001', $url);
        $this->assertStringContainsString('fecha=15-01-2024', $url);
        $this->assertStringContainsString('importe=121.00', $url);
    }

    public function testBuildQrUrlInProductionNoVerifactuMode(): void
    {
        config(['verifactu.verifactu_mode' => false]);

        $invoice = Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => 'FAC-002',
            'date' => \Carbon\Carbon::parse('2024-02-20'),
            'customer_name' => 'Test Customer',
            'customer_tax_id' => '87654321B',
            'issuer_name' => 'Test Company',
            'issuer_tax_id' => 'B87654321',
            'amount' => 200.00,
            'tax' => 42.00,
            'total' => 242.00,
            'type' => InvoiceType::STANDARD,
        ]);

        $url = QrUrlHelper::build($invoice, 'B87654321', true, false);

        $this->assertStringContainsString('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu', $url);
        $this->assertStringContainsString('nif=B87654321', $url);
        $this->assertStringContainsString('numserie=FAC-002', $url);
        $this->assertStringContainsString('fecha=20-02-2024', $url);
        $this->assertStringContainsString('importe=242.00', $url);
    }

    public function testBuildQrUrlInTestVerifactuMode(): void
    {
        config(['verifactu.verifactu_mode' => true]);

        $invoice = Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => 'FAC-003',
            'date' => \Carbon\Carbon::parse('2024-03-10'),
            'customer_name' => 'Test Customer',
            'customer_tax_id' => '11111111C',
            'issuer_name' => 'Test Company',
            'issuer_tax_id' => 'B11111111',
            'amount' => 50.00,
            'tax' => 10.50,
            'total' => 60.50,
            'type' => InvoiceType::STANDARD,
        ]);

        $url = QrUrlHelper::build($invoice, 'B11111111', false, true);

        $this->assertStringContainsString('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR', $url);
        $this->assertStringContainsString('nif=B11111111', $url);
        $this->assertStringContainsString('numserie=FAC-003', $url);
        $this->assertStringContainsString('fecha=10-03-2024', $url);
        $this->assertStringContainsString('importe=60.50', $url);
    }

    public function testBuildQrUrlInTestNoVerifactuMode(): void
    {
        config(['verifactu.verifactu_mode' => false]);

        $invoice = Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => 'FAC-004',
            'date' => \Carbon\Carbon::parse('2024-04-25'),
            'customer_name' => 'Test Customer',
            'customer_tax_id' => '22222222D',
            'issuer_name' => 'Test Company',
            'issuer_tax_id' => 'B22222222',
            'amount' => 150.00,
            'tax' => 31.50,
            'total' => 181.50,
            'type' => InvoiceType::STANDARD,
        ]);

        $url = QrUrlHelper::build($invoice, 'B22222222', false, false);

        $this->assertStringContainsString('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu', $url);
        $this->assertStringContainsString('nif=B22222222', $url);
        $this->assertStringContainsString('numserie=FAC-004', $url);
        $this->assertStringContainsString('fecha=25-04-2024', $url);
        $this->assertStringContainsString('importe=181.50', $url);
    }

    public function testQrUrlUsesConfigWhenModeNotProvided(): void
    {
        config(['verifactu.verifactu_mode' => false]);

        $invoice = Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => 'FAC-005',
            'date' => \Carbon\Carbon::parse('2024-05-15'),
            'customer_name' => 'Test Customer',
            'customer_tax_id' => '33333333E',
            'issuer_name' => 'Test Company',
            'issuer_tax_id' => 'B33333333',
            'amount' => 300.00,
            'tax' => 63.00,
            'total' => 363.00,
            'type' => InvoiceType::STANDARD,
        ]);

        // No pasamos el parámetro verifactuMode, debe usar el de config
        $url = QrUrlHelper::build($invoice, 'B33333333', true);

        $this->assertStringContainsString('ValidarQRNoVerifactu', $url);
    }
}
