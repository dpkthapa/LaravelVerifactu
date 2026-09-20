<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Squareetlabs\VeriFactu\Exceptions\VeriFactuException;
use Squareetlabs\VeriFactu\Helpers\HashHelper;

/**
 * Re-filing a registro AEAT answered "Incorrecto".
 */
class SubsanacionEnvelopeTest extends EnvelopeTestCase
{
    /** An ordinary alta gains no <Subsanacion> element at all. */
    public function testOrdinaryAltaHasNoSubsanacion(): void
    {
        $registro = $this->registro(
            $this->client()->sendInvoice($this->legacyInvoice([$this->legacyBreakdown()]))
        );

        $this->assertArrayNotHasKey('Subsanacion', $registro);
        $this->assertArrayNotHasKey('RechazoPrevio', $registro);
    }

    public function testSubsanacionEmitsTheFlag(): void
    {
        $registro = $this->registro(
            $this->client()->sendInvoice(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                null,
                subsanacion: true
            )
        );

        $this->assertSame('S', $registro['Subsanacion']);
        $this->assertArrayNotHasKey('RechazoPrevio', $registro);
    }

    public function testSubsanacionWithRechazoPrevio(): void
    {
        $registro = $this->registro(
            $this->client()->sendInvoice(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                null,
                subsanacion: true,
                rechazoPrevio: 'x'
            )
        );

        $this->assertSame('S', $registro['Subsanacion']);
        $this->assertSame('X', $registro['RechazoPrevio']);

        // Schema order: RechazoPrevio follows Subsanacion, which follows
        // NombreRazonEmisor.
        $keys = array_keys($registro);
        $this->assertSame(
            ['NombreRazonEmisor', 'Subsanacion', 'RechazoPrevio'],
            array_slice($keys, array_search('NombreRazonEmisor', $keys, true), 3)
        );
    }

    /** The IDFactura is unchanged — a subsanación re-files, it does not renumber. */
    public function testSubsanacionKeepsTheSameIdFactura(): void
    {
        $client = $this->client();
        $invoice = $this->legacyInvoice([$this->legacyBreakdown()]);

        $alta = $this->registro($client->sendInvoice($invoice));
        $sub = $this->registro($client->sendInvoice($invoice, null, subsanacion: true, rechazoPrevio: 'X'));

        $this->assertSame($alta['IDFactura'], $sub['IDFactura']);
    }

    public function testRechazoPrevioWithoutSubsanacionIsRefused(): void
    {
        $this->expectException(VeriFactuException::class);
        $this->expectExceptionMessage('subsanación');

        $this->client()->sendInvoice(
            $this->legacyInvoice([$this->legacyBreakdown()]),
            null,
            rechazoPrevio: 'X'
        );
    }

    public function testRechazoPrevioRejectsNonsenseValues(): void
    {
        $this->expectException(VeriFactuException::class);

        $this->client()->sendInvoice(
            $this->legacyInvoice([$this->legacyBreakdown()]),
            null,
            subsanacion: true,
            rechazoPrevio: 'YES'
        );
    }

    /**
     * A re-filed record is a NEW link in the chain: its own timestamp, its own
     * huella, and an Encadenamiento pointing at the tip as it stands now. The
     * caller supplies both, and the package must file them untouched.
     */
    public function testSubsanacionFilesTheCallersHuellaAndChain(): void
    {
        $previous = [
            'number' => 'FA-2026-000',
            'date' => '19-09-2026',
            'hash' => str_repeat('A', 64),
        ];

        $registro = $this->registro(
            $this->client()->sendInvoice(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                $previous,
                subsanacion: true,
                rechazoPrevio: 'X',
                huella: str_repeat('b', 64),
                generatedAt: '2026-09-20T11:22:33+00:00'
            )
        );

        $this->assertSame(str_repeat('B', 64), $registro['Huella']);
        $this->assertSame('2026-09-20T11:22:33+00:00', $registro['FechaHoraHusoGenRegistro']);
        $this->assertSame($previous['hash'], $registro['Encadenamiento']['RegistroAnterior']['Huella']);
        $this->assertArrayNotHasKey('PrimerRegistro', $registro['Encadenamiento']);
    }

    /**
     * Without a supplied huella the package computes one — and it must be the
     * same number HashHelper produces, because that is what the application
     * stored and chained into the next record.
     */
    public function testComputedHuellaMatchesHashHelper(): void
    {
        $ts = '2026-09-20T09:00:00+00:00';

        $registro = $this->registro(
            $this->client()->sendInvoice(
                $this->legacyInvoice([$this->legacyBreakdown()]),
                null,
                generatedAt: $ts
            )
        );

        $expected = HashHelper::generateInvoiceHash([
            'issuer_tax_id' => 'B11111111',
            'invoice_number' => 'FA-2026-001',
            'issue_date' => '20-09-2026',
            'invoice_type' => 'F1',
            'total_tax' => '21.00',
            'total_amount' => '121.00',
            'previous_hash' => '',
            'generated_at' => $ts,
        ])['hash'];

        $this->assertSame($expected, $registro['Huella']);
    }

    /**
     * A value carrying whitespace produced one huella in HashHelper (which
     * trims) and a different one in the client (which did not). One fork per
     * stray space. Both now trim, so both agree.
     */
    public function testWhitespaceDoesNotForkTheChain(): void
    {
        $ts = '2026-09-20T09:00:00+00:00';

        $clean = $this->registro($this->client()->sendInvoice(
            $this->issuerAwareInvoice([$this->legacyBreakdown()], 'A99999999', 'Outlet SL'),
            null,
            generatedAt: $ts
        ))['Huella'];

        $spaced = $this->registro($this->client()->sendInvoice(
            $this->issuerAwareInvoice([$this->legacyBreakdown()], ' A99999999 ', 'Outlet SL'),
            null,
            generatedAt: $ts
        ))['Huella'];

        $this->assertSame($clean, $spaced);
    }
}
