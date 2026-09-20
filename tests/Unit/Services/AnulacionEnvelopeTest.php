<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Squareetlabs\VeriFactu\Exceptions\VeriFactuException;
use Squareetlabs\VeriFactu\Helpers\HashHelper;

/**
 * <RegistroAnulacion>, including the SinRegistroPrevio the package could not
 * emit at all.
 */
class AnulacionEnvelopeTest extends EnvelopeTestCase
{
    public function testCancellationNamesTheInvoiceWithAnuladaElements(): void
    {
        $result = $this->client()->sendCancellation($this->legacyInvoice([$this->legacyBreakdown()]));
        $registro = $this->registro($result, 'RegistroAnulacion');

        $this->assertSame([
            'IDEmisorFacturaAnulada' => 'B11111111',
            'NumSerieFacturaAnulada' => 'FA-2026-001',
            'FechaExpedicionFacturaAnulada' => '20-09-2026',
        ], $registro['IDFactura']);

        // An anulación states that a record is void, not what it was worth.
        $this->assertArrayNotHasKey('TipoFactura', $registro);
        $this->assertArrayNotHasKey('CuotaTotal', $registro);
        $this->assertArrayNotHasKey('ImporteTotal', $registro);
        $this->assertArrayNotHasKey('Desglose', $registro);
    }

    public function testSinRegistroPrevioIsOmittedByDefault(): void
    {
        $registro = $this->registro(
            $this->client()->sendCancellation($this->legacyInvoice([$this->legacyBreakdown()])),
            'RegistroAnulacion'
        );

        $this->assertArrayNotHasKey('SinRegistroPrevio', $registro);
    }

    public function testSinRegistroPrevioIsEmittedWhenAsked(): void
    {
        $registro = $this->registro(
            $this->client()->sendCancellation(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                null,
                sinRegistroPrevio: true
            ),
            'RegistroAnulacion'
        );

        $this->assertSame('S', $registro['SinRegistroPrevio']);

        // Schema order: RefExterna, SinRegistroPrevio, RechazoPrevio,
        // Encadenamiento.
        $keys = array_keys($registro);
        $this->assertLessThan(
            array_search('Encadenamiento', $keys, true),
            array_search('SinRegistroPrevio', $keys, true)
        );
    }

    public function testCancellationRechazoPrevio(): void
    {
        $registro = $this->registro(
            $this->client()->sendCancellation(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                null,
                sinRegistroPrevio: true,
                rechazoPrevio: 'S'
            ),
            'RegistroAnulacion'
        );

        $this->assertSame('S', $registro['RechazoPrevio']);
    }

    /** 'X' belongs to a RegistroAlta; an anulación takes S or N. */
    public function testCancellationRejectsAltaOnlyRechazoPrevio(): void
    {
        $this->expectException(VeriFactuException::class);

        $this->client()->sendCancellation(
            $this->legacyInvoice([$this->legacyBreakdown()]),
            null,
            rechazoPrevio: 'X'
        );
    }

    /**
     * The anulación hashes the *Anulada field names, with no TipoFactura,
     * CuotaTotal or ImporteTotal. Hashing it like an alta produces a
     * fingerprint AEAT cannot reproduce.
     */
    public function testCancellationHuellaUsesTheCancellationFieldSet(): void
    {
        $ts = '2026-09-20T12:00:00+00:00';
        $previous = ['number' => 'FA-2026-000', 'date' => '19-09-2026', 'hash' => str_repeat('C', 64)];

        $registro = $this->registro(
            $this->client()->sendCancellation(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                $previous,
                generatedAt: $ts
            ),
            'RegistroAnulacion'
        );

        $expected = HashHelper::generateCancellationHash([
            'issuer_tax_id' => 'B11111111',
            'invoice_number' => 'FA-2026-001',
            'issue_date' => '20-09-2026',
            'previous_hash' => str_repeat('C', 64),
            'generated_at' => $ts,
        ]);

        $this->assertSame($expected['hash'], $registro['Huella']);
        $this->assertSame(
            'IDEmisorFacturaAnulada=B11111111'
            . '&NumSerieFacturaAnulada=FA-2026-001'
            . '&FechaExpedicionFacturaAnulada=20-09-2026'
            . '&Huella=' . str_repeat('C', 64)
            . '&FechaHoraHusoGenRegistro=' . $ts,
            $expected['inputString']
        );
    }

    /** A stored huella is filed verbatim, never re-hashed. */
    public function testCancellationFilesTheCallersHuella(): void
    {
        $registro = $this->registro(
            $this->client()->sendCancellation(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                null,
                huella: str_repeat('d', 64)
            ),
            'RegistroAnulacion'
        );

        $this->assertSame(str_repeat('D', 64), $registro['Huella']);
    }

    public function testFirstRecordInTheChain(): void
    {
        $registro = $this->registro(
            $this->client()->sendCancellation($this->legacyInvoice([$this->legacyBreakdown()])),
            'RegistroAnulacion'
        );

        $this->assertSame(['PrimerRegistro' => 'S'], $registro['Encadenamiento']);
    }
}
