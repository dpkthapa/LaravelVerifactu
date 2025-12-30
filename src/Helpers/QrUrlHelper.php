<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Helpers;

use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;

class QrUrlHelper
{
    public const PRE_URL_VF = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
    public const PROD_URL_VF = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR';
    public const PRE_URL_NOVF = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu';
    public const PROD_URL_NOVF = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu';

    /**
     * Build QR URL for an invoice
     *
     * @param VeriFactuInvoice $invoice
     * @param string $issuerVat NIF of the issuer
     * @param bool $production Whether to use production or test environment
     * @param bool|null $verifactuMode Whether to use VERIFACTU mode (true) or NO VERIFACTU mode (false)
     * @return string
     */
    public static function build(
        VeriFactuInvoice $invoice,
        string $issuerVat,
        bool $production = false,
        ?bool $verifactuMode = null
    ): string {
        $verifactuMode = $verifactuMode ?? config('verifactu.verifactu_mode', true);

        $base = self::getBaseUrl($production, $verifactuMode);

        $nif = $issuerVat;
        $numserie = (string) $invoice->getInvoiceNumber();
        $fecha = $invoice->getIssueDate()->format('d-m-Y');
        $importe = number_format((float) $invoice->getTotalAmount(), 2, '.', '');

        $query = http_build_query([
            'nif' => $nif,
            'numserie' => $numserie,
            'fecha' => $fecha,
            'importe' => $importe,
        ], '', '&', PHP_QUERY_RFC3986);

        return $base . '?' . $query;
    }

    /**
     * Get the base URL for QR validation
     *
     * @param bool $production
     * @param bool $verifactuMode
     * @return string
     */
    protected static function getBaseUrl(bool $production, bool $verifactuMode): string
    {
        if ($verifactuMode) {
            return $production ? self::PROD_URL_VF : self::PRE_URL_VF;
        }

        return $production ? self::PROD_URL_NOVF : self::PRE_URL_NOVF;
    }
}
