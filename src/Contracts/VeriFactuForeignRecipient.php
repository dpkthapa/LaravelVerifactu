<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Contracts;

/**
 * Optional companion to VeriFactuRecipient, for customers who are not Spanish
 * taxpayers.
 *
 * AEAT identifies a recipient in one of two mutually exclusive ways:
 *
 *   <NIF>            a Spanish tax identifier, and nothing else, or
 *   <IDOtro>         <CodigoPais> + <IDType> + <ID>, for everyone else.
 *
 * A foreign passport or an intra-community VAT number placed in <NIF> is not a
 * formatting slip: it declares a Spanish taxpayer who does not exist. That is
 * what happened before this interface existed, because the recipient contract
 * had no way to say "this person is not Spanish" — a restaurant serving tourists
 * had nowhere to put their identity.
 *
 * SEPARATE interface, not extra methods on VeriFactuRecipient, and deliberately
 * so: adding required methods to the existing contract would fatal every
 * application already implementing it the moment they updated. Implement this
 * one only if you have foreign customers. AeatClient also accepts a recipient
 * that merely HAS these methods without declaring the interface, so an existing
 * model with a getCountry() already works untouched.
 */
interface VeriFactuForeignRecipient
{
    /**
     * ISO 3166-1 alpha-2 country code of the recipient, e.g. 'FR', 'GB', 'NP'.
     *
     * Return 'ES' or null for a Spanish taxpayer — that routes the identifier
     * back to <NIF>, which is what Spain requires and what existing behaviour
     * already does.
     *
     * @return string|null
     */
    public function getCountry(): ?string;

    /**
     * Which kind of document getTaxId() returns, as a ForeignIdType or its
     * string value ('02' VAT, '03' passport, '04' national ID, '05' residence
     * certificate, '06' other, '07' unregistered).
     *
     * Return null to let AeatClient decide: it uses '02' when the identifier
     * carries the country's own VAT prefix (FR…, DE…), and otherwise '06',
     * "other supporting document". '06' is the honest default — calling an
     * unknown document a VAT number would be a false statement in a filed
     * record, while '06' is exactly what it claims to be.
     *
     * @return \Squareetlabs\VeriFactu\Enums\ForeignIdType|string|null
     */
    public function getForeignIdType();
}
