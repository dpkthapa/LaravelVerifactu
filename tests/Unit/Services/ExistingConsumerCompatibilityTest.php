<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Support\Collection;
use Squareetlabs\VeriFactu\Contracts\VeriFactuBreakdown;
use Squareetlabs\VeriFactu\Contracts\VeriFactuBreakdownExtras;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;

/**
 * A real integration's models, reproduced signature for signature.
 *
 * These are not invented shapes. They mirror an application that implements
 * these contracts on its own Eloquent models today — decimal casts returning
 * strings, a non-nullable getTaxType(), surcharge getters that answer 0 when
 * the buyer is not under the regime, and the E-code living in operation_type
 * because that is where AEAT's own vocabulary naturally goes.
 *
 * The point is not that the XML is right; the other tests cover that. It is
 * that this class LOADS, which it could not do against the previous contract
 * (getTaxRate(): float cannot be satisfied by a decimal cast), and that adding
 * the extras to a model changes nothing for the rows that have nothing to add.
 */
class ExistingConsumerCompatibilityTest extends EnvelopeTestCase
{
    public function testTheConsumersBreakdownSatisfiesTheOptionalContract(): void
    {
        $this->assertInstanceOf(VeriFactuBreakdownExtras::class, $this->consumerBreakdown());
        $this->assertInstanceOf(VeriFactuBreakdown::class, $this->consumerBreakdown());
    }

    /**
     * An ordinary S1 row, read out of decimal-cast strings, is byte-identical
     * to what the five-getter contract produced.
     */
    public function testOrdinaryRowIsUnchanged(): void
    {
        $row = $this->registro($this->client()->sendInvoice(
            $this->consumerInvoice([$this->consumerBreakdown()])
        ))['Desglose']['DetalleDesglose'][0];

        $this->assertSame([
            'Impuesto' => '01',
            'ClaveRegimen' => '01',
            'CalificacionOperacion' => 'S1',
            'TipoImpositivo' => 21.0,
            'BaseImponibleOimporteNoSujeto' => '100.00',
            'CuotaRepercutida' => '21.00',
        ], $row);
    }

    /** A surcharged row files both halves, and the header reconciles. */
    public function testSurchargedRowFilesTheSurcharge(): void
    {
        $invoice = $this->consumerInvoice(
            [$this->consumerBreakdown(['recargo_rate' => '5.20', 'recargo_amount' => '5.20'])],
            ['total' => '126.20', 'tax' => '26.20']
        );

        $registro = $this->registro($this->client()->sendInvoice($invoice));
        $row = $registro['Desglose']['DetalleDesglose'][0];

        $this->assertSame(5.2, $row['TipoRecargoEquivalencia']);
        $this->assertSame('5.20', $row['CuotaRecargoEquivalencia']);

        // ImporteTotal = Σ(base + cuota + recargo) = 100 + 21 + 5.20.
        $this->assertSame('126.20', $registro['ImporteTotal']);
    }

    /** The E-code stored in operation_type becomes OperacionExenta. */
    public function testExemptRowFromOperationTypeColumn(): void
    {
        $invoice = $this->consumerInvoice(
            [$this->consumerBreakdown([
                'operation_type' => 'E1',
                'tax_rate' => '0.00',
                'tax_amount' => '0.00',
            ])],
            ['total' => '100.00', 'tax' => '0.00']
        );

        $row = $this->registro($this->client()->sendInvoice($invoice))['Desglose']['DetalleDesglose'][0];

        $this->assertSame('E1', $row['OperacionExenta']);
        $this->assertArrayNotHasKey('CalificacionOperacion', $row);
    }

    /** The invoice's own NIF reaches the IDFactura, the Cabecera and the huella. */
    public function testTheInvoicesOwnIssuerIsUsed(): void
    {
        $result = $this->client()->sendInvoice(
            $this->consumerInvoice([$this->consumerBreakdown()]),
            null,
            generatedAt: '2026-09-20T09:00:00+00:00'
        );

        $registro = $this->registro($result);

        $this->assertSame('A99999999', $registro['IDFactura']['IDEmisorFactura']);
        $this->assertSame('Outlet SL', $registro['NombreRazonEmisor']);
        $this->assertSame('A99999999', $result['body']['Cabecera']['ObligadoEmision']['NIF']);
    }

    // ---------------------------------------------------------------------

    private function consumerBreakdown(array $attributes = []): VeriFactuBreakdown
    {
        $attributes += [
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'exempt_reason' => null,
            'tax_rate' => '21.00',
            'base_amount' => '100.00',
            'tax_amount' => '21.00',
            'recargo_rate' => null,
            'recargo_amount' => null,
        ];

        return new class ($attributes) implements VeriFactuBreakdown, VeriFactuBreakdownExtras {
            public function __construct(private array $a)
            {
            }

            public function getTaxType(): string
            {
                return $this->a['tax_type'];
            }

            public function getRegimeType(): string
            {
                return $this->a['regime_type'];
            }

            public function getOperationType(): string
            {
                return $this->a['operation_type'];
            }

            // Eloquent's decimal:2 cast returns a string, not a float.
            public function getTaxRate(): float|string
            {
                return $this->a['tax_rate'];
            }

            public function getBaseAmount(): float
            {
                return (float) $this->a['base_amount'];
            }

            public function getTaxAmount(): float
            {
                return (float) $this->a['tax_amount'];
            }

            public function getSurchargeRate(): float
            {
                return (float) ($this->a['recargo_rate'] ?? 0);
            }

            public function getSurchargeAmount(): float
            {
                return (float) ($this->a['recargo_amount'] ?? 0);
            }

            public function getExemptReason(): ?string
            {
                return $this->a['exempt_reason'] ?: null;
            }

            public function getExemptionCause(): ?string
            {
                // This consumer keeps the E-code in operation_type; the free-text
                // exempt_reason is a note, not an AEAT code, and must never be
                // filed as one.
                return null;
            }
        };
    }

    private function consumerInvoice(array $breakdowns, array $attributes = []): VeriFactuInvoice
    {
        $attributes += [
            'number' => 'TX-9001',
            'date' => '2026-09-20',
            'total' => '121.00',
            'tax' => '21.00',
        ];

        return new class ($attributes, collect($breakdowns)) implements VeriFactuInvoice {
            public function __construct(private array $a, private Collection $breakdowns)
            {
            }

            public function getInvoiceNumber(): string
            {
                return $this->a['number'];
            }

            public function getIssueDate(): \Carbon\Carbon
            {
                return \Carbon\Carbon::parse($this->a['date']);
            }

            public function getInvoiceType(): string
            {
                return 'F2';
            }

            public function getTotalAmount(): float
            {
                return (float) $this->a['total'];
            }

            public function getTaxAmount(): float
            {
                return (float) $this->a['tax'];
            }

            public function getCustomerName(): string
            {
                return 'Walk-in customer';
            }

            public function getCustomerTaxId(): ?string
            {
                return null;
            }

            public function getBreakdowns(): Collection
            {
                return $this->breakdowns;
            }

            public function getRecipients(): Collection
            {
                return collect();
            }

            public function getPreviousHash(): ?string
            {
                return null;
            }

            public function getOperationDescription(): string
            {
                return 'POS sale';
            }

            public function getOperationDate(): ?\Carbon\Carbon
            {
                return null;
            }

            public function getTaxPeriod(): ?string
            {
                return null;
            }

            public function getCorrectionType(): ?string
            {
                return null;
            }

            public function getExternalReference(): ?string
            {
                return null;
            }

            public function getRectifiedInvoices(): array
            {
                return [];
            }

            public function getCorrectedBaseAmount(): ?float
            {
                return null;
            }

            public function getCorrectedTaxAmount(): ?float
            {
                return null;
            }

            public function getCorrectedSurchargeAmount(): ?float
            {
                return null;
            }

            // Not contract members — the client duck-types them.
            public function getIssuerTaxId(): ?string
            {
                return 'A99999999';
            }

            public function getIssuerName(): ?string
            {
                return 'Outlet SL';
            }
        };
    }
}
