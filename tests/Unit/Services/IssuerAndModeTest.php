<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Squareetlabs\VeriFactu\Exceptions\VeriFactuException;
use Squareetlabs\VeriFactu\Services\AeatClient;

/**
 * Who the registro says is filing it, and what happens in the mode this
 * package does not implement.
 */
class IssuerAndModeTest extends EnvelopeTestCase
{
    /** An invoice that says nothing about its issuer still files config's. */
    public function testConfiguredIssuerIsUsedWhenTheInvoiceIsSilent(): void
    {
        $result = $this->client()->sendInvoice($this->legacyInvoice([$this->legacyBreakdown()]));
        $registro = $this->registro($result);

        $this->assertSame('B11111111', $registro['IDFactura']['IDEmisorFactura']);
        $this->assertSame('Configured Issuer SL', $registro['NombreRazonEmisor']);
        $this->assertSame('B11111111', $result['body']['Cabecera']['ObligadoEmision']['NIF']);
    }

    /**
     * An invoice that carries its own NIF wins — everywhere, including the
     * huella.
     *
     * config('verifactu.issuer') holds today's answer; the invoice holds the
     * one its stored fingerprint was built from. A retry after an outlet's NIF
     * changed used to file a huella that could not be reproduced from the
     * record.
     */
    public function testInvoiceIssuerOverridesConfigEverywhere(): void
    {
        $result = $this->client()->sendInvoice(
            $this->issuerAwareInvoice([$this->legacyBreakdown()], 'A99999999', 'Outlet SL'),
            null,
            record: ['generated_at' => '2026-09-20T09:00:00+00:00']
        );

        $registro = $this->registro($result);

        $this->assertSame('A99999999', $registro['IDFactura']['IDEmisorFactura']);
        $this->assertSame('Outlet SL', $registro['NombreRazonEmisor']);
        $this->assertSame('A99999999', $result['body']['Cabecera']['ObligadoEmision']['NIF']);
        $this->assertSame('A99999999', $registro['SistemaInformatico']['NIF']);

        // And the fingerprint: same invoice, different NIF, different huella.
        $withConfigured = $this->registro($this->client()->sendInvoice(
            $this->legacyInvoice([$this->legacyBreakdown()]),
            null,
            record: ['generated_at' => '2026-09-20T09:00:00+00:00']
        ));

        $this->assertNotSame($withConfigured['Huella'], $registro['Huella']);
    }

    /** An empty issuer on the invoice never blanks out the configured one. */
    public function testEmptyInvoiceIssuerFallsBackToConfig(): void
    {
        $registro = $this->registro($this->client()->sendInvoice(
            $this->issuerAwareInvoice([$this->legacyBreakdown()], '   ', '')
        ));

        $this->assertSame('B11111111', $registro['IDFactura']['IDEmisorFactura']);
        $this->assertSame('Configured Issuer SL', $registro['NombreRazonEmisor']);
    }

    /**
     * The per-instance issuer (upstream v2.0.0) and the per-invoice issuer are
     * not two versions of the same idea, and the precedence between them is
     * load-bearing.
     *
     * A client configured for one obligado, filing an invoice that records a
     * different one, files the INVOICE's — that is the NIF its stored huella
     * was built from. One queue worker builds one client and serves every
     * outlet, so this is the ordinary case, not the exotic one.
     */
    public function testInvoiceIssuerBeatsThePerInstanceIssuer(): void
    {
        $client = $this->clientWith(['name' => 'Instance SL', 'vat' => 'B22222222']);

        // The invoice knows who issued it.
        $registro = $this->registro($client->sendInvoice(
            $this->issuerAwareInvoice([$this->legacyBreakdown()], 'A99999999', 'Outlet SL')
        ));
        $this->assertSame('A99999999', $registro['IDFactura']['IDEmisorFactura']);

        // It does not: the instance's issuer stands, not config's.
        $registro = $this->registro($client->sendInvoice(
            $this->legacyInvoice([$this->legacyBreakdown()])
        ));
        $this->assertSame('B22222222', $registro['IDFactura']['IDEmisorFactura']);
        $this->assertSame('Instance SL', $registro['NombreRazonEmisor']);
    }

    /**
     * A colaborador social files anulaciones too. The Representante block comes
     * from buildHeader(), so sendCancellation() inherits it for free — the two
     * halves of this merge meeting in one envelope.
     */
    public function testRepresentativeReachesTheCancellationHeader(): void
    {
        $client = $this->clientWith(null, ['name' => 'Gestoría SL', 'vat' => 'B33333333']);

        $result = $client->sendCancellation($this->legacyInvoice([$this->legacyBreakdown()]));

        $this->assertSame(
            ['NombreRazon' => 'Gestoría SL', 'NIF' => 'B33333333'],
            $result['body']['Cabecera']['Representante']
        );
    }

    /**
     * NO-VERIFACTU (requerimiento) mode fails loudly instead of filing an
     * envelope with no RemisionRequerimiento, no RefRequerimiento and no XAdES
     * signature.
     */
    public function testRequerimientoModeRefusesToSend(): void
    {
        config(['verifactu.verifactu_mode' => false]);

        $client = new class ('/dev/null', null, false, false) extends AeatClient {
            protected function performSoapCall(
                array $body,
                string $huella,
                string $numSerie,
                string $fechaExp,
                string $ts,
                ?array $previous
            ): array {
                throw new \LogicException('should never be reached');
            }
        };

        $this->expectException(VeriFactuException::class);
        $this->expectExceptionMessage('XAdES');

        $client->sendInvoice($this->legacyInvoice([$this->legacyBreakdown()]));
    }

    public function testRequerimientoModeAlsoRefusesCancellations(): void
    {
        $client = new class ('/dev/null', null, false, false) extends AeatClient {
            protected function performSoapCall(
                array $body,
                string $huella,
                string $numSerie,
                string $fechaExp,
                string $ts,
                ?array $previous
            ): array {
                throw new \LogicException('should never be reached');
            }
        };

        $this->expectException(VeriFactuException::class);

        $client->sendCancellation($this->legacyInvoice([$this->legacyBreakdown()]));
    }

    /**
     * The full RegistroAlta shape for an ordinary invoice, as a regression
     * anchor: every element the builder emits, in the order it emits them.
     */
    public function testOrdinaryAltaShape(): void
    {
        $registro = $this->registro($this->client()->sendInvoice(
            $this->legacyInvoice([$this->legacyBreakdown()])
        ));

        $this->assertSame([
            'IDVersion',
            'IDFactura',
            'NombreRazonEmisor',
            'TipoFactura',
            'DescripcionOperacion',
            'Desglose',
            'CuotaTotal',
            'ImporteTotal',
            'Encadenamiento',
            'SistemaInformatico',
            'FechaHoraHusoGenRegistro',
            'TipoHuella',
            'Huella',
        ], array_keys($registro));
    }
}
