<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Contracts;

use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Contract that invoice models must implement to be compatible with VeriFactu
 */
interface VeriFactuInvoice
{
    /**
     * Get the invoice number/series
     *
     * @return string
     */
    public function getInvoiceNumber(): string;

    /**
     * Get the invoice issue date
     *
     * @return Carbon
     */
    public function getIssueDate(): Carbon;

    /**
     * Get the invoice type according to AEAT specifications
     * Valid values: F1, F2, F3, F4, R1, R2, R3, R4, R5
     *
     * @return string
     */
    public function getInvoiceType(): string;

    /**
     * Get the total amount in euros (including tax)
     *
     * @return float
     */
    public function getTotalAmount(): float;

    /**
     * Get the total tax amount in euros
     *
     * @return float
     */
    public function getTaxAmount(): float;

    /**
     * Get the customer/recipient name
     *
     * @return string
     */
    public function getCustomerName(): string;

    /**
     * Get the customer tax ID (NIF/CIF)
     * Can be null for foreign customers or when using IDOtro
     *
     * @return string|null
     */
    public function getCustomerTaxId(): ?string;

    /**
     * Get the invoice breakdowns (tax details by regime/rate)
     * Must return at least one breakdown
     *
     * @return Collection<VeriFactuBreakdown>
     */
    public function getBreakdowns(): Collection;

    /**
     * Get additional recipients (optional, for multiple recipients)
     * Return empty collection if not applicable
     *
     * @return Collection<VeriFactuRecipient>
     */
    public function getRecipients(): Collection;

    /**
     * Get the previous invoice hash for chaining
     * Return null for the first invoice in the chain
     *
     * @return string|null
     */
    public function getPreviousHash(): ?string;

    /**
     * Get the operation description
     *
     * @return string
     */
    public function getOperationDescription(): string;

    /**
     * Get the operation date (if different from issue date)
     *
     * @return Carbon|null
     */
    public function getOperationDate(): ?Carbon;

    /**
     * Get the tax period (e.g., "01", "02", "0A")
     *
     * @return string|null
     */
    public function getTaxPeriod(): ?string;

    /**
     * Get the correction type (e.g., "S", "I")
     * Required for corrective invoices (R1-R5)
     *
     * @return string|null
     */
    public function getCorrectionType(): ?string;

    /**
     * Get the external reference (optional)
     *
     * @return string|null
     */
    public function getExternalReference(): ?string;

    /**
     * Get the corrected base amount (for substitution corrective invoices)
     * Required when TipoRectificativa = "S"
     * This is the original base amount from the invoice being corrected
     *
     * @return float|null
     */
    public function getCorrectedBaseAmount(): ?float;

    /**
     * Get the corrected tax amount (for substitution corrective invoices)
     * Required when TipoRectificativa = "S"
     * This is the original tax amount from the invoice being corrected
     *
     * @return float|null
     */
    public function getCorrectedTaxAmount(): ?float;

    /**
     * Get the corrected surcharge amount (for substitution corrective invoices)
     * Optional field for AEAT ImporteRectificacion block
     * This is the original surcharge amount from the invoice being corrected
     *
     * @return float|null
     */
    public function getCorrectedSurchargeAmount(): ?float;
}
