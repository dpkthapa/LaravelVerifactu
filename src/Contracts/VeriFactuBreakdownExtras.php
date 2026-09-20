<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Contracts;

/**
 * The parts of a <DetalleDesglose> row that VeriFactuBreakdown does not carry.
 *
 * OPTIONAL. Implement it, or just declare the individual methods — AeatClient
 * reads every member with method_exists() and never type-hints this interface,
 * exactly as it does with VeriFactuForeignRecipient. It exists so an
 * application can see, in one place, what the envelope is able to say about a
 * row; implementing it is a statement of intent, not a requirement.
 *
 * Nothing here is emitted unless the row actually supplies it, so adding the
 * methods to an existing model cannot change the XML of a row that has no
 * surcharge and is not exempt.
 */
interface VeriFactuBreakdownExtras
{
    /**
     * <Impuesto> for this row: '01' IVA, '02' IPSI, '03' IGIC, '05' otros.
     *
     * Consulted ONLY when the client cannot determine the tax from the
     * obligado's own configuration, so an application that already resolves
     * IVA/IGIC per outlet keeps that answer. 'IVA', 'IGIC' and 'IPSI' are
     * accepted as well as the numeric codes; anything unrecognised falls back
     * to '01'.
     *
     * @return string|null
     */
    public function getTaxType(): ?string;

    /**
     * <TipoRecargoEquivalencia> — the recargo de equivalencia percentage, e.g.
     * 5.2 alongside a 21% IVA row. Return 0 when the buyer is not under the
     * regime; the element is then omitted entirely.
     *
     * @return float
     */
    public function getSurchargeRate(): float;

    /**
     * <CuotaRecargoEquivalencia> — the surcharge in euros.
     *
     * AEAT counts it inside ImporteTotal: ImporteTotal must equal
     * Σ(BaseImponibleOimporteNoSujeto + CuotaRepercutida +
     * CuotaRecargoEquivalencia). The client does NOT recompute ImporteTotal
     * from the rows — that number is an input to the huella and belongs to the
     * caller — but it does reconcile the two and warn when they disagree.
     *
     * @return float
     */
    public function getSurchargeAmount(): float;

    /**
     * <OperacionExenta> — 'E1'..'E6', or null when the row is not exempt.
     *
     * Only needed when the exemption cause is stored somewhere other than
     * getOperationType(); an E-code returned by that getter is routed to
     * OperacionExenta on its own.
     *
     * NOTE ON NAMING: SII called this element CausaExencion, inside a separate
     * <DetalleExenta> block. VeriFactu has no CausaExencion and no
     * DetalleExenta — the cause IS the E-code, carried by <OperacionExenta> in
     * the same slot CalificacionOperacion would occupy.
     *
     * @return string|null
     */
    public function getExemptionCause(): ?string;
}
