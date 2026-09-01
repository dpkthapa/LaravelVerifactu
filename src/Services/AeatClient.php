<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Enums\ForeignIdType;
use Squareetlabs\VeriFactu\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;


class AeatClient
{
    /**
     * Countries whose tax identifiers carry the country code as a VAT prefix.
     *
     * Used ONLY to guess IDType when the application does not declare one, and
     * kept narrow on purpose: guessing '02' outside this list turns an ordinary
     * document number into a claimed VAT registration. ES is included for
     * completeness though a Spanish recipient never reaches IDOtro.
     */
    private const EU_VAT_COUNTRIES = [
        'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE',
        'IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','XI',
    ];

    private string $baseUri;
    private string $certPath;
    private ?string $certPassword;
    private Client $client;
    private bool $production;
    private bool $verifactuMode;

    public function __construct(string $certPath, ?string $certPassword = null, bool $production = false, ?bool $verifactuMode = null)
    {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
        $this->production = $production;
        $this->verifactuMode = $verifactuMode ?? config('verifactu.verifactu_mode', true);
        // $this->baseUri = $production
        //     ? 'https://www2.aeat.es'
        //     : 'https://prewww1.aeat.es';
        // $this->client = new Client([
        //     'cert' => ($certPassword === null) ? $certPath : [$certPath, $certPassword],
        //     'base_uri' => $this->baseUri,
        //     'headers' => [
        //         'User-Agent' => 'LaravelVerifactu/1.0',
        //     ],
        // ]);
    }



    /**
     * Build fingerprint/hash for invoice chaining
     *
     * @param string $issuerVat
     * @param string $numSerie
     * @param string $fechaExp
     * @param string $tipoFactura
     * @param string $cuotaTotal
     * @param string $importeTotal
     * @param string $ts
     * @param string $prevHash
     * @return string
     */
    private function buildFingerprint(
        string $issuerVat,
        string $numSerie,
        string $fechaExp,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        string $ts,
        string $prevHash = ''
    ): string {
        $raw = 'IDEmisorFactura=' . $issuerVat
            . '&NumSerieFactura=' . $numSerie
            . '&FechaExpedicionFactura=' . $fechaExp
            . '&TipoFactura=' . $tipoFactura
            . '&CuotaTotal=' . $cuotaTotal
            . '&ImporteTotal=' . $importeTotal
            . '&Huella=' . $prevHash
            . '&FechaHoraHusoGenRegistro=' . $ts;
        return strtoupper(hash('sha256', $raw));
    }

    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param Invoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param VeriFactuInvoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param VeriFactuInvoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    public function sendInvoice(VeriFactuInvoice $invoice, ?array $previous = null): array
    {
        // 1. Obtener datos del emisor
        $issuer = config('verifactu.issuer');
        $issuerName = $issuer['name'] ?? '';
        $issuerVat = $issuer['vat'] ?? '';

        // 2. Preparar datos comunes
        $ts = \Carbon\Carbon::now('UTC')->format('c');
        $numSerie = (string) $invoice->getInvoiceNumber();
        $fechaExp = $invoice->getIssueDate()->format('d-m-Y');
        $tipoFactura = $invoice->getInvoiceType();
        $cuotaTotal = sprintf('%.2f', (float) $invoice->getTaxAmount());
        $importeTotal = sprintf('%.2f', (float) $invoice->getTotalAmount());
        $prevHash = $previous['hash'] ?? $invoice->getPreviousHash() ?? '';

        // 3. Generar huella
        $huella = $this->buildFingerprint(
            $issuerVat,
            $numSerie,
            $fechaExp,
            $tipoFactura,
            $cuotaTotal,
            $importeTotal,
            $ts,
            $prevHash
        );

        // 4. Construir partes del mensaje
        $cabecera = $this->buildHeader($issuerName, $issuerVat);
        $detalle = $this->buildBreakdowns($invoice);
        $encadenamiento = $this->buildChaining($previous, $issuerVat);
        $destinatarios = $this->buildRecipients($invoice);

        // 5. Construir RegistroAlta
        $registroAlta = $this->buildRegistration(
            $invoice,
            $issuerName,
            $issuerVat,
            $numSerie,
            $fechaExp,
            $tipoFactura,
            $cuotaTotal,
            $importeTotal,
            $ts,
            $huella,
            $detalle,
            $encadenamiento,
            $destinatarios
        );

        $body = [
            'Cabecera' => $cabecera,
            'RegistroFactura' => [
                ['RegistroAlta' => $registroAlta]
            ],
        ];

        // 6. Enviar
        return $this->performSoapCall($body, $huella, $numSerie, $fechaExp, $ts, $previous);
    }

    private function buildHeader(string $issuerName, string $issuerVat): array
    {
        return [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
            ],
        ];
    }

    private function buildBreakdowns(VeriFactuInvoice $invoice): array
    {
        $breakdowns = $invoice->getBreakdowns();
        $detalle = [];

        $taxType = $this->getBranchTaxType($invoice);
        $isIgicForInvoice = ($taxType === 'IGIC');

        foreach ($breakdowns as $breakdown) {
            $rateRaw = $breakdown->getTaxRate();

            if (is_string($rateRaw)) {
                $rateRaw = str_replace(',', '.', trim($rateRaw));
            }
            $rate = round((float) $rateRaw, 2);

            $detalle[] = [
                'Impuesto' => $isIgicForInvoice ? '03' : '01',
                'ClaveRegimen' => $breakdown->getRegimeType(),
                'CalificacionOperacion' => $breakdown->getOperationType(),
                'TipoImpositivo' => $rate,
                'BaseImponibleOimporteNoSujeto' => sprintf('%.2f', (float) $breakdown->getBaseAmount()),
                'CuotaRepercutida' => sprintf('%.2f', (float) $breakdown->getTaxAmount()),
            ];
        }

        if (count($detalle) === 0) {
            $base = sprintf('%.2f', (float) $invoice->getTotalAmount() - $invoice->getTaxAmount());
            $detalle[] = [
                'Impuesto' => '01',
                'ClaveRegimen' => '01',
                'CalificacionOperacion' => 'S1',
                'TipoImpositivo' => 0.0,
                'BaseImponibleOimporteNoSujeto' => $base,
                'CuotaRepercutida' => sprintf('%.2f', 0.0),
            ];
        }

        return $detalle;
    }


    private function buildChaining(?array $previous, string $issuerVat): array
    {
        if ($previous) {
            return [
                'RegistroAnterior' => [
                    'IDEmisorFactura' => $issuerVat,
                    'NumSerieFactura' => $previous['number'],
                    'FechaExpedicionFactura' => $previous['date'],
                    'Huella' => $previous['hash'],
                ],
            ];
        }
        return ['PrimerRegistro' => 'S'];
    }

    private function buildRecipients(VeriFactuInvoice $invoice): ?array
    {
        $recipients = $invoice->getRecipients();
        if ($recipients->count() > 0) {
            $destinatarios = [];
            foreach ($recipients as $recipient) {
                $r = ['NombreRazon' => $recipient->getName()];
                $taxId = trim((string) ($recipient->getTaxId() ?? ''));

                if ($taxId !== '') {
                    $country = $this->recipientCountry($recipient);

                    // NIF and IDOtro are mutually exclusive, and which one is
                    // correct depends on WHO the recipient is, not on the shape
                    // of the string. Everything used to go into NIF, so a French
                    // VAT number or a passport was filed as a Spanish tax
                    // identifier — a declaration about a taxpayer who does not
                    // exist, not a formatting slip.
                    //
                    // Spanish, or country not stated -> NIF, exactly as before,
                    // so an application that never sets a country keeps its
                    // current behaviour byte for byte.
                    if ($country === '' || $country === 'ES') {
                        $r['NIF'] = $taxId;
                    } else {
                        $r['IDOtro'] = [
                            'CodigoPais' => $country,
                            'IDType'     => $this->foreignIdType($recipient, $taxId, $country),
                            'ID'         => $taxId,
                        ];
                    }
                }

                $destinatarios[] = $r;
            }
            return ['IDDestinatario' => $destinatarios];
        }
        return null;
    }

    /**
     * ISO 3166-1 alpha-2 country for a recipient, or '' when it does not say.
     *
     * Duck-typed rather than requiring VeriFactuForeignRecipient: a model that
     * already exposes getCountry() works untouched, which is the point of not
     * changing the original contract.
     */
    private function recipientCountry($recipient): string
    {
        if (!method_exists($recipient, 'getCountry')) {
            return '';
        }

        $country = strtoupper(trim((string) ($recipient->getCountry() ?? '')));

        // Only a real alpha-2 code routes to IDOtro. A full country name, a
        // stray '0', a three-letter code — all fall back to the previous NIF
        // behaviour rather than emitting a CodigoPais AEAT will reject, because
        // a rejected record is worse than an imperfect one.
        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '';
    }

    /**
     * Which document IDOtro carries: '02' VAT … '07' unregistered.
     *
     * An explicit answer from the recipient always wins. Absent one, an
     * identifier carrying its own country's VAT prefix (FR12345678901) is taken
     * as a VAT number and everything else as '06', "other supporting document".
     * '06' is deliberately the fallback: it is exactly what it claims, whereas
     * labelling an unknown document '02' asserts a VAT registration that may
     * not exist.
     */
    private function foreignIdType($recipient, string $taxId, string $country): string
    {
        if (method_exists($recipient, 'getForeignIdType')) {
            $declared = $recipient->getForeignIdType();

            if ($declared instanceof ForeignIdType) {
                return $declared->value;
            }

            $declared = trim((string) ($declared ?? ''));
            if ($declared !== '' && ForeignIdType::tryFrom($declared) instanceof ForeignIdType) {
                return $declared;
            }
        }

        // The "identifier begins with its country code" convention is an EU VAT
        // one, and only inside the EU does it mean anything. Applied globally it
        // misfires on ordinary document numbers that merely happen to start with
        // two matching letters — a Nepali document 'NP998877' for country NP
        // came back as IDType 02, asserting a VAT registration that does not
        // exist. Outside the EU the honest answer is always '06'.
        $prefix = strtoupper($taxId);

        // Greece is the one country whose VAT prefix is not its country code.
        $vatPrefix = $country === 'GR' ? 'EL' : $country;

        $looksLikeEuVat = in_array($country, self::EU_VAT_COUNTRIES, true)
            && str_starts_with($prefix, $vatPrefix)
            // A bare prefix is not a number; require identifier characters after it.
            && preg_match('/^[A-Z]{2}[0-9A-Z]{2,}$/', $prefix) === 1;

        return $looksLikeEuVat
            ? ForeignIdType::VAT->value
            : ForeignIdType::OTHER_DOCUMENT->value;
    }

    private function buildRegistration(
        VeriFactuInvoice $invoice,
        string $issuerName,
        string $issuerVat,
        string $numSerie,
        string $fechaExp,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        string $ts,
        string $huella,
        array $detalle,
        array $encadenamiento,
        ?array $destinatarios
    ): array {
        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura' => $issuerVat,
                'NumSerieFactura' => $numSerie,
                'FechaExpedicionFactura' => $fechaExp,
            ],
            'NombreRazonEmisor' => $issuerName,
            'TipoFactura' => $tipoFactura,
            'DescripcionOperacion' => $invoice->getOperationDescription(),
            'Desglose' => ['DetalleDesglose' => $detalle],
            'CuotaTotal' => $cuotaTotal,
            'ImporteTotal' => $importeTotal,
            'Encadenamiento' => $encadenamiento,
            'SistemaInformatico' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
                'NombreSistemaInformatico' => config('verifactu.sistema_informatico.name', 'LaravelVerifactu'),
                'IdSistemaInformatico' => config('verifactu.sistema_informatico.id', 'LV'),
                'Version' => config('verifactu.sistema_informatico.version', '1.0'),
                'NumeroInstalacion' => config('verifactu.sistema_informatico.installation_number', '001'),
                'TipoUsoPosibleSoloVerifactu' => config('verifactu.sistema_informatico.only_verifactu_capable', 'S'),
                'TipoUsoPosibleMultiOT' => config('verifactu.sistema_informatico.multi_obligated_entities_capable', 'N'),
                'IndicadorMultiplesOT' => config('verifactu.sistema_informatico.has_multiple_obligated_entities', 'N'),
            ],
            'FechaHoraHusoGenRegistro' => $ts,
            'TipoHuella' => '01',
            'Huella' => $huella,
        ];

        // Campos opcionales nuevos
        if ($invoice->getOperationDate()) {
            $registroAlta['FechaOperacion'] = $invoice->getOperationDate()->format('d-m-Y');
        }

        if ($invoice->getTaxPeriod()) {
            $registroAlta['PeriodoImpositivo'] = [
                'Ejercicio' => $invoice->getIssueDate()->format('Y'),
                'Periodo' => $invoice->getTaxPeriod(),
            ];
        }

        if ($invoice->getCorrectionType()) {
            $registroAlta['TipoRectificativa'] = $invoice->getCorrectionType();

            // Add ImporteRectificacion block if required
            if ($invoice->getCorrectionType() === 'S' && $this->isCorrectiveInvoice($tipoFactura)) {
                $importeRectificacion = $this->buildImporteRectificacion($invoice);
                if ($importeRectificacion) {
                    $registroAlta['ImporteRectificacion'] = $importeRectificacion;
                }
            }
        }

        if ($invoice->getExternalReference()) {
            $registroAlta['RefExterna'] = $invoice->getExternalReference();
        }

        if ($destinatarios) {
            $registroAlta['Destinatarios'] = $destinatarios;
        }

        return $registroAlta;
    }

    /**
     * Build ImporteRectificacion block for substitution corrective invoices
     *
     * @param VeriFactuInvoice $invoice
     * @return array|null
     */
    private function buildImporteRectificacion(VeriFactuInvoice $invoice): ?array
    {
        $baseRectificada = $invoice->getCorrectedBaseAmount();
        $cuotaRectificada = $invoice->getCorrectedTaxAmount();

        // Both base and tax are required
        if ($baseRectificada === null || $cuotaRectificada === null) {
            return null;
        }

        $importe = [
            'BaseRectificada' => sprintf('%.2f', $baseRectificada),
            'CuotaRectificada' => sprintf('%.2f', $cuotaRectificada),
        ];

        // Add optional surcharge if present
        $cuotaRecargo = $invoice->getCorrectedSurchargeAmount();
        if ($cuotaRecargo !== null) {
            $importe['CuotaRecargoRectificado'] = sprintf('%.2f', $cuotaRecargo);
        }

        return $importe;
    }

    /**
     * Check if invoice type is corrective (R1-R5)
     *
     * @param string $tipoFactura
     * @return bool
     */
    private function isCorrectiveInvoice(string $tipoFactura): bool
    {
        return in_array($tipoFactura, ['R1', 'R2', 'R3', 'R4', 'R5']);
    }

    protected function getSoapClient(): \SoapClient
    {
        // AEAT publishes the WSDL as a static file
        $wsdl = $this->production
            ? 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl'
            : 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';

        return new \SoapClient($wsdl, [
            'local_cert' => $this->certPath,
            'passphrase' => $this->certPassword,
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'soap_version' => SOAP_1_1,
            'connection_timeout' => 30,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ],
                'http' => [
                    'user_agent' => 'LaravelVerifactu/1.0',
                ],
            ]),
        ]);
    }

    private function performSoapCall(
        array $body,
        string $huella,
        string $numSerie,
        string $fechaExp,
        string $ts,
        ?array $previous
    ): array {
        if ($this->production) {
            $location = $this->verifactuMode
                ? 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
                : 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP';
        } else {
            $location = $this->verifactuMode
                ? 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
                : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP';
        }

        $client = null;

        try {
            $client = $this->getSoapClient();
            $client->__setLocation($location);

            // Log::info('AEAT SOAP call started', [
            //     'endpoint' => $location,
            //     'invoice_number' => $numSerie,
            //     'invoice_date' => $fechaExp,
            //     'timestamp' => $ts,
            //     'verifactu_mode' => $this->verifactuMode,
            //     'production' => $this->production,
            //     'body_array' => $body, // useful for debugging before XML serialization
            // ]);

            $response = $client->__soapCall('RegFactuSistemaFacturacion', [$body]);

            $lastRequestXml = $client->__getLastRequest();
            $lastResponseXml = $client->__getLastResponse();
            $lastRequestHeaders = $client->__getLastRequestHeaders();
            $lastResponseHeaders = $client->__getLastResponseHeaders();

            Log::info('AEAT SOAP success', [
                'endpoint' => $location,
                'invoice_number' => $numSerie,
                'request_headers' => $lastRequestHeaders,
                'request_xml' => $lastRequestXml,
                'response_headers' => $lastResponseHeaders,
                'response_xml' => $lastResponseXml,
                'parsed_response' => $response,
                'hash' => $huella,
                'first' => $previous ? false : true,
            ]);

            return [
                'status' => 'success',
                'request' => $lastRequestXml,
                'request_headers' => $lastRequestHeaders,
                'response' => $lastResponseXml,
                'response_headers' => $lastResponseHeaders,
                'aeat_response' => $response,
                'hash' => $huella,
                'number' => $numSerie,
                'date' => $fechaExp,
                'timestamp' => $ts,
                'first' => $previous ? false : true,
            ];
        } catch (\SoapFault $e) {
            $lastRequestXml = $client ? $client->__getLastRequest() : null;
            $lastResponseXml = $client ? $client->__getLastResponse() : null;
            $lastRequestHeaders = $client ? $client->__getLastRequestHeaders() : null;
            $lastResponseHeaders = $client ? $client->__getLastResponseHeaders() : null;

            Log::error('AEAT SOAP fault', [
                'endpoint' => $location,
                'invoice_number' => $numSerie,
                'error_message' => $e->getMessage(),
                'fault_code' => $e->faultcode ?? null,
                'fault_string' => $e->faultstring ?? null,
                'request_headers' => $lastRequestHeaders,
                'request_xml' => $lastRequestXml,
                'response_headers' => $lastResponseHeaders,
                'response_xml' => $lastResponseXml,
                'body_array' => $body,
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'fault_code' => $e->faultcode ?? null,
                'fault_string' => $e->faultstring ?? null,
                'request' => $lastRequestXml,
                'request_headers' => $lastRequestHeaders,
                'response' => $lastResponseXml,
                'response_headers' => $lastResponseHeaders,
            ];
        }
    }

    private function getBranchTaxType(VeriFactuInvoice $invoice): string
    {
        $branchId = null;

        if ($invoice instanceof Model) {
            $branchId = $invoice->getAttribute('branch_id');
        }

        if ($branchId) {
            $val = DB::table('sys_config')
                ->where('branch_id', $branchId)
                ->where('config_keys', 'tax_type')
                ->value('config_values');

            $val = strtoupper(trim((string) $val));
            if (in_array($val, ['IVA', 'IGIC'], true)) {
                return $val;
            }
        }

        return 'IVA'; // safe default
    }
}
