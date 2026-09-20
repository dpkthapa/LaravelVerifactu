<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Log;

/**
 * <DetalleDesglose>: what a row says, and what it must not say.
 */
class DesgloseTest extends EnvelopeTestCase
{
    /**
     * THE BACKWARD-COMPATIBILITY WITNESS.
     *
     * A breakdown implementing only the original five getters must still
     * produce the same six elements, in the same order, with the same values.
     * If this ever fails, an application that upgraded without changing a line
     * has had its filings altered underneath it.
     */
    public function testLegacyBreakdownIsUnchanged(): void
    {
        $client = $this->client();
        $result = $client->sendInvoice($this->legacyInvoice([$this->legacyBreakdown()]));

        $rows = $this->registro($result)['Desglose']['DetalleDesglose'];

        $this->assertCount(1, $rows);
        $this->assertSame([
            'Impuesto' => '01',
            'ClaveRegimen' => '01',
            'CalificacionOperacion' => 'S1',
            'TipoImpositivo' => 21.0,
            'BaseImponibleOimporteNoSujeto' => '100.00',
            'CuotaRepercutida' => '21.00',
        ], $rows[0]);

        // Element ORDER, not just contents.
        $this->assertSame(
            ['Impuesto', 'ClaveRegimen', 'CalificacionOperacion', 'TipoImpositivo',
                'BaseImponibleOimporteNoSujeto', 'CuotaRepercutida'],
            array_keys($rows[0])
        );
    }

    /** A rich breakdown with nothing to declare is byte-identical to a legacy one. */
    public function testRichBreakdownWithNoExtrasMatchesLegacy(): void
    {
        $client = $this->client();

        $legacy = $this->registro(
            $client->sendInvoice($this->legacyInvoice([$this->legacyBreakdown()]))
        )['Desglose']['DetalleDesglose'][0];

        $rich = $this->registro(
            $client->sendInvoice($this->legacyInvoice([$this->richBreakdown()]))
        )['Desglose']['DetalleDesglose'][0];

        $this->assertSame($legacy, $rich);
    }

    /** Recargo de equivalencia: both halves, after CuotaRepercutida. */
    public function testSurchargeIsEmitted(): void
    {
        $client = $this->client();

        $invoice = $this->legacyInvoice(
            [$this->richBreakdown([
                'rate' => 21.0,
                'base' => 100.0,
                'tax' => 21.0,
                'surcharge_rate' => 5.2,
                'surcharge_amount' => 5.20,
            ])],
            // ImporteTotal = base + cuota + recargo.
            ['total' => 126.20, 'tax' => 21.00]
        );

        $row = $this->registro($client->sendInvoice($invoice))['Desglose']['DetalleDesglose'][0];

        $this->assertSame(5.2, $row['TipoRecargoEquivalencia']);
        $this->assertSame('5.20', $row['CuotaRecargoEquivalencia']);
        $this->assertSame(
            ['Impuesto', 'ClaveRegimen', 'CalificacionOperacion', 'TipoImpositivo',
                'BaseImponibleOimporteNoSujeto', 'CuotaRepercutida',
                'TipoRecargoEquivalencia', 'CuotaRecargoEquivalencia'],
            array_keys($row)
        );
    }

    /**
     * AEAT identifies a desglose line by (Impuesto, ClaveRegimen,
     * CalificacionOperacion, TipoImpositivo, TipoRecargoEquivalencia).
     *
     * Two rows at the same tipo with different surcharge rates were therefore
     * indistinguishable while TipoRecargoEquivalencia was not emitted: two
     * identical lines, which is not a legal desglose. This is the arithmetic
     * fix AND the identity fix, so assert the identity explicitly.
     */
    public function testTwoRowsDifferingOnlyBySurchargeRemainDistinguishable(): void
    {
        $client = $this->client();

        $invoice = $this->legacyInvoice(
            [
                $this->richBreakdown([
                    'rate' => 21.0, 'base' => 100.0, 'tax' => 21.0,
                    'surcharge_rate' => 5.2, 'surcharge_amount' => 5.20,
                ]),
                $this->richBreakdown([
                    'rate' => 21.0, 'base' => 50.0, 'tax' => 10.50,
                    'surcharge_rate' => 0.0, 'surcharge_amount' => 0.0,
                ]),
            ],
            ['total' => 186.70, 'tax' => 31.50]
        );

        $rows = $this->registro($client->sendInvoice($invoice))['Desglose']['DetalleDesglose'];

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0], $rows[1]);

        $identity = static fn (array $r): string => implode('|', [
            $r['Impuesto'],
            $r['ClaveRegimen'],
            $r['CalificacionOperacion'],
            $r['TipoImpositivo'],
            $r['TipoRecargoEquivalencia'] ?? '',
        ]);

        $this->assertNotSame($identity($rows[0]), $identity($rows[1]));
        $this->assertSame('01|01|S1|21|5.2', $identity($rows[0]));
        $this->assertSame('01|01|S1|21|', $identity($rows[1]));
    }

    /**
     * An exempt supply files OperacionExenta INSTEAD of CalificacionOperacion,
     * and carries no tipo and no cuota.
     */
    public function testExemptRowFilesOperacionExenta(): void
    {
        $client = $this->client();

        $invoice = $this->legacyInvoice(
            [$this->legacyBreakdown('01', 'E1', 0.0, 100.0, 0.0)],
            ['total' => 100.00, 'tax' => 0.00]
        );

        $row = $this->registro($client->sendInvoice($invoice))['Desglose']['DetalleDesglose'][0];

        $this->assertSame('E1', $row['OperacionExenta']);
        $this->assertArrayNotHasKey('CalificacionOperacion', $row);
        $this->assertArrayNotHasKey('TipoImpositivo', $row);
        $this->assertArrayNotHasKey('CuotaRepercutida', $row);
        $this->assertSame('100.00', $row['BaseImponibleOimporteNoSujeto']);
    }

    /** An explicit getExemptionCause() beats whatever getOperationType() says. */
    public function testExplicitExemptionCauseWins(): void
    {
        $client = $this->client();

        $invoice = $this->legacyInvoice(
            [$this->richBreakdown([
                'operation' => 'S1',
                'exemption' => 'E5',
                'rate' => 0.0, 'base' => 200.0, 'tax' => 0.0,
            ])],
            ['total' => 200.00, 'tax' => 0.00]
        );

        $row = $this->registro($client->sendInvoice($invoice))['Desglose']['DetalleDesglose'][0];

        $this->assertSame('E5', $row['OperacionExenta']);
        $this->assertArrayNotHasKey('CalificacionOperacion', $row);
    }

    /** N1/N2 keep CalificacionOperacion but lose tipo and cuota. */
    public function testNotSubjectRowHasNoRateAndNoQuota(): void
    {
        $client = $this->client();

        $invoice = $this->legacyInvoice(
            [$this->legacyBreakdown('01', 'N1', 0.0, 80.0, 0.0)],
            ['total' => 80.00, 'tax' => 0.00]
        );

        $row = $this->registro($client->sendInvoice($invoice))['Desglose']['DetalleDesglose'][0];

        $this->assertSame('N1', $row['CalificacionOperacion']);
        $this->assertArrayNotHasKey('OperacionExenta', $row);
        $this->assertArrayNotHasKey('TipoImpositivo', $row);
        $this->assertArrayNotHasKey('CuotaRepercutida', $row);
        $this->assertSame('80.00', $row['BaseImponibleOimporteNoSujeto']);
    }

    /** An exempt or not-subject row never grows a surcharge. */
    public function testExemptRowCarriesNoSurcharge(): void
    {
        $client = $this->client();

        $invoice = $this->legacyInvoice(
            [$this->richBreakdown([
                'operation' => 'E2',
                'rate' => 0.0, 'base' => 100.0, 'tax' => 0.0,
                'surcharge_rate' => 5.2, 'surcharge_amount' => 5.20,
            ])],
            ['total' => 100.00, 'tax' => 0.00]
        );

        $row = $this->registro($client->sendInvoice($invoice))['Desglose']['DetalleDesglose'][0];

        $this->assertArrayNotHasKey('TipoRecargoEquivalencia', $row);
        $this->assertArrayNotHasKey('CuotaRecargoEquivalencia', $row);
    }

    /** A string rate from an Eloquent decimal cast still normalises. */
    public function testStringRateIsNormalised(): void
    {
        $client = $this->client();

        $row = $this->registro($client->sendInvoice(
            $this->legacyInvoice([$this->legacyBreakdown('01', 'S1', '10,00', 100.0, 10.0)])
        ))['Desglose']['DetalleDesglose'][0];

        $this->assertSame(10.0, $row['TipoImpositivo']);
    }

    /** With no configured tax for the obligado, the row's own Impuesto is used. */
    public function testBreakdownCanDeclareItsOwnImpuesto(): void
    {
        $client = $this->client();

        $row = $this->registro($client->sendInvoice(
            $this->legacyInvoice([$this->richBreakdown(['tax_type' => 'IGIC', 'rate' => 7.0])])
        ))['Desglose']['DetalleDesglose'][0];

        $this->assertSame('03', $row['Impuesto']);
    }

    /**
     * A header that does not add up to the rows is reported, not corrected.
     *
     * ImporteTotal and CuotaTotal are inputs to the huella the caller already
     * stored and chained; silently substituting recomputed ones would trade a
     * rejection AEAT explains for a chain break it does not.
     */
    public function testMismatchedTotalsAreWarnedAboutAndNotRewritten(): void
    {
        Log::spy();

        $invoice = $this->legacyInvoice(
            [$this->richBreakdown([
                'rate' => 21.0, 'base' => 100.0, 'tax' => 21.0,
                'surcharge_rate' => 5.2, 'surcharge_amount' => 5.20,
            ])],
            // The surcharge left out of both totals: the shape of the bug this
            // catches.
            ['total' => 121.00, 'tax' => 21.00]
        );

        $registro = $this->registro($this->client()->sendInvoice($invoice));

        $this->assertSame('121.00', $registro['ImporteTotal']);
        $this->assertSame('21.00', $registro['CuotaTotal']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m): bool => str_contains($m, 'ImporteTotal does not match'))
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m): bool => str_contains($m, 'CuotaTotal does not match'))
            ->once();
    }

    /** A consistent invoice says nothing. */
    public function testConsistentTotalsWarnAboutNothing(): void
    {
        Log::spy();

        $this->client()->sendInvoice($this->legacyInvoice([$this->legacyBreakdown()]));

        Log::shouldNotHaveReceived('warning');
    }

    /** An unrecognised Impuesto falls back to IVA rather than filing nonsense. */
    public function testUnknownImpuestoFallsBackToVat(): void
    {
        $client = $this->client();

        $row = $this->registro($client->sendInvoice(
            $this->legacyInvoice([$this->richBreakdown(['tax_type' => 'VAT-ish'])])
        ))['Desglose']['DetalleDesglose'][0];

        $this->assertSame('01', $row['Impuesto']);
    }
}
