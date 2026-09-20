<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Support\Collection;
use Squareetlabs\VeriFactu\Contracts\VeriFactuBreakdown;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Tests\TestCase;

/**
 * Builds a real envelope through the real sendInvoice()/sendCancellation() and
 * hands back the array that would have been serialised, plus the XML it
 * serialises to.
 *
 * No certificate, no network, no mocking of the thing under test: only the SOAP
 * call itself is intercepted, at the one seam (performSoapCall) that exists for
 * it. Everything above that seam — the issuer resolution, the huella, the
 * desglose, the ordering — is the code that runs in production.
 */
abstract class EnvelopeTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('verifactu.issuer', [
            'name' => 'Configured Issuer SL',
            'vat' => 'B11111111',
        ]);
        $app['config']->set('verifactu.verifactu_mode', true);
    }

    protected function client(): AeatClient
    {
        return new class ('/dev/null', null, false, true) extends AeatClient {
            public array $body = [];

            protected function performSoapCall(
                array $body,
                string $huella,
                string $numSerie,
                string $fechaExp,
                string $ts,
                ?array $previous
            ): array {
                $this->body = $body;

                return ['status' => 'captured', 'hash' => $huella, 'body' => $body];
            }
        };
    }

    /** The RegistroAlta (or RegistroAnulacion) out of a captured envelope. */
    protected function registro(array $result, string $key = 'RegistroAlta'): array
    {
        return $result['body']['RegistroFactura'][0][$key];
    }

    /**
     * Serialise a registro to XML in the order the builder emitted it.
     *
     * PHP's WSDL-mode SoapClient orders elements from the schema, so this is
     * not byte-for-byte what AEAT receives. It IS a faithful rendering of what
     * the builder decided to say, which is what these tests are about: whether
     * an element is present, absent, or carries the wrong value.
     */
    protected function toXml(array $registro, string $root = 'RegistroAlta'): string
    {
        $render = function (array $node, int $depth) use (&$render): string {
            $pad = str_repeat('  ', $depth);
            $out = '';

            foreach ($node as $name => $value) {
                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $item) {
                        $out .= $pad . "<{$name}>\n" . $render($item, $depth + 1) . $pad . "</{$name}>\n";
                    }
                    continue;
                }

                if (is_array($value)) {
                    $out .= $pad . "<{$name}>\n" . $render($value, $depth + 1) . $pad . "</{$name}>\n";
                    continue;
                }

                $out .= $pad . "<{$name}>" . htmlspecialchars((string) $value) . "</{$name}>\n";
            }

            return $out;
        };

        return "<{$root}>\n" . $render($registro, 1) . "</{$root}>\n";
    }

    /**
     * A breakdown implementing ONLY the original five-method contract.
     *
     * The backward-compatibility witness: whatever is added to the client, a
     * row built from this class must keep producing the same six elements.
     */
    protected function legacyBreakdown(
        string $regime = '01',
        string $operation = 'S1',
        float|string $rate = 21.0,
        float $base = 100.0,
        float $tax = 21.0
    ): VeriFactuBreakdown {
        return new class ($regime, $operation, $rate, $base, $tax) implements VeriFactuBreakdown {
            public function __construct(
                private string $regime,
                private string $operation,
                private float|string $rate,
                private float $base,
                private float $tax
            ) {
            }

            public function getRegimeType(): string
            {
                return $this->regime;
            }

            public function getOperationType(): string
            {
                return $this->operation;
            }

            public function getTaxRate(): float|string
            {
                return $this->rate;
            }

            public function getBaseAmount(): float
            {
                return $this->base;
            }

            public function getTaxAmount(): float
            {
                return $this->tax;
            }
        };
    }

    /** A breakdown that also answers the optional extras. */
    protected function richBreakdown(array $attributes = []): VeriFactuBreakdown
    {
        $attributes += [
            'regime' => '01',
            'operation' => 'S1',
            'rate' => 21.0,
            'base' => 100.0,
            'tax' => 21.0,
            'surcharge_rate' => 0.0,
            'surcharge_amount' => 0.0,
            'exemption' => null,
            'tax_type' => null,
        ];

        return new class ($attributes) implements VeriFactuBreakdown {
            public function __construct(private array $a)
            {
            }

            public function getRegimeType(): string
            {
                return $this->a['regime'];
            }

            public function getOperationType(): string
            {
                return $this->a['operation'];
            }

            public function getTaxRate(): float|string
            {
                return $this->a['rate'];
            }

            public function getBaseAmount(): float
            {
                return (float) $this->a['base'];
            }

            public function getTaxAmount(): float
            {
                return (float) $this->a['tax'];
            }

            public function getSurchargeRate(): float
            {
                return (float) $this->a['surcharge_rate'];
            }

            public function getSurchargeAmount(): float
            {
                return (float) $this->a['surcharge_amount'];
            }

            public function getExemptionCause(): ?string
            {
                return $this->a['exemption'];
            }

            public function getTaxType(): ?string
            {
                return $this->a['tax_type'];
            }
        };
    }

    /**
     * An invoice implementing ONLY the contract as published in 1.0 — no
     * getIssuerTaxId(), no getRectifiedInvoices(), no corrected amounts.
     */
    protected function legacyInvoice(array $breakdowns, array $attributes = []): VeriFactuInvoice
    {
        $attributes += [
            'number' => 'FA-2026-001',
            'date' => '2026-09-20',
            'type' => 'F1',
            'total' => 121.00,
            'tax' => 21.00,
            'customer' => 'Cliente SL',
            'customer_tax_id' => null,
            'previous_hash' => null,
            'description' => 'Venta',
            'correction_type' => null,
            'external_reference' => null,
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
                return $this->a['type'];
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
                return $this->a['customer'];
            }

            public function getCustomerTaxId(): ?string
            {
                return $this->a['customer_tax_id'];
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
                return $this->a['previous_hash'];
            }

            public function getOperationDescription(): string
            {
                return $this->a['description'];
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
                return $this->a['correction_type'];
            }

            public function getExternalReference(): ?string
            {
                return $this->a['external_reference'];
            }
        };
    }

    /** The same invoice, but declaring its own issuer the way the app's does. */
    protected function issuerAwareInvoice(array $breakdowns, string $vat, string $name, array $attributes = []): VeriFactuInvoice
    {
        $base = $this->legacyInvoice($breakdowns, $attributes);

        return new class ($base, $vat, $name) implements VeriFactuInvoice {
            public function __construct(private VeriFactuInvoice $inner, private string $vat, private string $name)
            {
            }

            public function getIssuerTaxId(): ?string
            {
                return $this->vat;
            }

            public function getIssuerName(): ?string
            {
                return $this->name;
            }

            public function getInvoiceNumber(): string
            {
                return $this->inner->getInvoiceNumber();
            }

            public function getIssueDate(): \Carbon\Carbon
            {
                return $this->inner->getIssueDate();
            }

            public function getInvoiceType(): string
            {
                return $this->inner->getInvoiceType();
            }

            public function getTotalAmount(): float
            {
                return $this->inner->getTotalAmount();
            }

            public function getTaxAmount(): float
            {
                return $this->inner->getTaxAmount();
            }

            public function getCustomerName(): string
            {
                return $this->inner->getCustomerName();
            }

            public function getCustomerTaxId(): ?string
            {
                return $this->inner->getCustomerTaxId();
            }

            public function getBreakdowns(): Collection
            {
                return $this->inner->getBreakdowns();
            }

            public function getRecipients(): Collection
            {
                return $this->inner->getRecipients();
            }

            public function getPreviousHash(): ?string
            {
                return $this->inner->getPreviousHash();
            }

            public function getOperationDescription(): string
            {
                return $this->inner->getOperationDescription();
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
                return $this->inner->getCorrectionType();
            }

            public function getExternalReference(): ?string
            {
                return $this->inner->getExternalReference();
            }
        };
    }
}
