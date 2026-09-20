<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Contracts;

/**
 * Contract for invoice tax breakdown details — one <DetalleDesglose> row.
 *
 * The five members below are the original contract and are unchanged. Anything
 * a row may ALSO carry (the recargo de equivalencia, an exemption cause, its
 * own Impuesto) is optional and read through VeriFactuBreakdownExtras or plain
 * method_exists(), so a five-method implementation written against 1.0 keeps
 * producing byte-identical XML.
 *
 * @see VeriFactuBreakdownExtras
 */
interface VeriFactuBreakdown
{
    /**
     * Get the regime type (ClaveRegimen)
     * Common values: '01' (General regime), '02' (Export), etc.
     *
     * @return string
     */
    public function getRegimeType(): string;

    /**
     * The row's operation qualifier.
     *
     * Two different AEAT elements are reached through this one getter, because
     * they occupy the same slot in the schema and are mutually exclusive:
     *
     *   S1, S2, N1, N2  -> <CalificacionOperacion>
     *   E1 .. E6        -> <OperacionExenta>
     *
     * E-codes are NOT valid CalificacionOperacion values, so returning one used
     * to file an invalid qualifier; AeatClient now routes it to OperacionExenta
     * instead. An implementation that would rather be explicit can declare
     * getExemptionCause() (see VeriFactuBreakdownExtras) and that answer wins.
     *
     * @return string
     */
    public function getOperationType(): string;

    /**
     * Get the tax rate as a percentage (e.g., 21.0 for 21% VAT)
     *
     * Widened to float|string: AeatClient has always normalised a string rate
     * ("21,00"), and Eloquent's `decimal:2` cast hands back a string — so an
     * implementation reading such a column could not satisfy a bare `float`
     * return type and fatalled the moment the class was loaded. Returning a
     * plain float still satisfies this, so nothing that compiled before stops
     * compiling.
     *
     * @return float|string
     */
    public function getTaxRate(): float|string;

    /**
     * Get the base amount in euros (amount before tax)
     *
     * @return float
     */
    public function getBaseAmount(): float;

    /**
     * Get the tax amount in euros
     *
     * @return float
     */
    public function getTaxAmount(): float;
}
