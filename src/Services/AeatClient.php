<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Enums\ForeignIdType;
use Squareetlabs\VeriFactu\Enums\TaxType;
use Squareetlabs\VeriFactu\Exceptions\VeriFactuException;
use Squareetlabs\VeriFactu\Helpers\HashHelper;
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
        'IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','GB',
    ];

    /**
     * Countries whose VAT prefix is not simply the country code.
     *
     * Greece files as EL. Northern Ireland trades under XI while its ISO country
     * is GB, so a GB recipient may legitimately present either. 'XI' is NOT a
     * country here — it is not an ISO 3166-1 code and must never reach
     * CodigoPais.
     */
    private const VAT_PREFIX_OVERRIDES = [
        'GR' => ['EL'],
        'GB' => ['GB', 'XI'],
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
     * Build fingerprint/hash for invoice chaining.
     *
     * Delegates to HashHelper rather than repeating the concatenation, because
     * the two copies had already drifted: HashHelper::field() trims each value
     * and this method did not, so a stored value carrying a stray space
     * (' A00000000', a number pasted with a trailing newline) produced one
     * huella in the application's own record and a DIFFERENT one in the
     * envelope actually filed — and the next registro chained to the first.
     * A fork, from a character nobody can see.
     *
     * One implementation, one answer. The input string is unchanged for any
     * value that was already clean, so every huella computed before this still
     * reproduces exactly.
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
        return HashHelper::generateInvoiceHash([
            'issuer_tax_id'  => $issuerVat,
            'invoice_number' => $numSerie,
            'issue_date'     => $fechaExp,
            'invoice_type'   => $tipoFactura,
            'total_tax'      => $cuotaTotal,
            'total_amount'   => $importeTotal,
            'previous_hash'  => $prevHash,
            'generated_at'   => $ts,
        ])['hash'];
    }

    /**
     * WHO is filing this registro: the invoice's own issuer, then config.
     *
     * config('verifactu.issuer') is a process-wide setting; an invoice is a
     * record of something that already happened. When an outlet's NIF changes,
     * or one worker serves several obligados, config holds today's answer while
     * the stored huella was built from the NIF the invoice carries — so a retry
     * would file a fingerprint that cannot be reproduced from the record, and
     * the Cabecera would name an obligado that never issued the document.
     *
     * The getters are optional (method_exists), and an Eloquent
     * issuer_tax_id / issuer_name attribute is read too, so an application that
     * exposes neither keeps getting exactly what config says. An empty value
     * never overrides config.
     *
     * @return array{name: string, vat: string}
     */
    private function resolveIssuer(VeriFactuInvoice $invoice): array
    {
        $configured = config('verifactu.issuer');

        return [
            'name' => $this->issuerField($invoice, 'getIssuerName', 'issuer_name')
                ?? (string) ($configured['name'] ?? ''),
            'vat' => $this->issuerField($invoice, 'getIssuerTaxId', 'issuer_tax_id')
                ?? (string) ($configured['vat'] ?? ''),
        ];
    }

    /** One issuer field, from a getter or an Eloquent attribute, or null. */
    private function issuerField(VeriFactuInvoice $invoice, string $getter, string $attribute): ?string
    {
        if (method_exists($invoice, $getter)) {
            $value = trim((string) ($invoice->{$getter}() ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ($invoice instanceof Model) {
            $value = trim((string) ($invoice->getAttribute($attribute) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * File a RegistroAlta with AEAT.
     *
     * Every argument after $previous is optional and defaults to the behaviour
     * this method has always had, so an existing `sendInvoice($invoice, $prev)`
     * call is untouched.
     *
     * ── SUBSANACIÓN ──────────────────────────────────────────────────────────
     *
     * When AEAT answers EstadoRegistro = "Incorrecto" the registro is NOT
     * filed, and RD 1007/2023 art. 8 expects a corrected record under the SAME
     * IDFactura rather than a new invoice number. That record is an ordinary
     * RegistroAlta carrying <Subsanacion>S</Subsanacion>:
     *
     *     $client->sendInvoice($invoice, $previous, subsanacion: true, rechazoPrevio: 'X');
     *
     * The subsanación is a NEW entry in the chain, not a rewrite of the old
     * one: it gets its own FechaHoraHusoGenRegistro, its own huella, and an
     * Encadenamiento pointing at whatever the chain tip is NOW. Pass that tip
     * as $previous and the freshly computed fingerprint as $huella — the
     * package will not re-hash behind the caller's back.
     *
     * $rechazoPrevio may only be sent alongside subsanacion: true; AEAT rejects
     * it otherwise, and so does this method. See normaliseRechazoPrevio() for
     * the values and for what is and is not verified about them.
     *
     * @param VeriFactuInvoice $invoice
     * @param array|null $previous Previous registro for chaining: ['hash','number','date' (d-m-Y)]
     * @param bool $subsanacion Emit <Subsanacion>S</Subsanacion> — this re-files a rejected registro
     * @param string|null $rechazoPrevio <RechazoPrevio>, only with $subsanacion
     * @param string|null $huella File this fingerprint verbatim instead of recomputing one
     * @param string|null $generatedAt Replay this FechaHoraHusoGenRegistro (ISO-8601) instead of now()
     * @return array
     */
    public function sendInvoice(
        VeriFactuInvoice $invoice,
        ?array $previous = null,
        bool $subsanacion = false,
        ?string $rechazoPrevio = null,
        ?string $huella = null,
        ?string $generatedAt = null
    ): array {
        $this->assertVerifactuMode();

        $rechazoPrevio = $this->normaliseRechazoPrevio($rechazoPrevio, $subsanacion, 'RegistroAlta');

        // 1. Obtener datos del emisor — de la propia factura primero.
        $issuer = $this->resolveIssuer($invoice);
        $issuerName = $issuer['name'];
        $issuerVat = $issuer['vat'];

        // 2. Preparar datos comunes
        //
        // $generatedAt lets the caller replay the exact timestamp its stored
        // huella was built from. Without it the only way to make the two agree
        // was to freeze the global clock around this call
        // (Carbon::setTestNow()), which is a heavy thing to do inside a queue
        // worker that is also serving other jobs.
        $ts = $generatedAt !== null && trim($generatedAt) !== ''
            ? trim($generatedAt)
            : \Carbon\Carbon::now('UTC')->format('c');
        $numSerie = (string) $invoice->getInvoiceNumber();
        $fechaExp = $invoice->getIssueDate()->format('d-m-Y');
        $tipoFactura = $invoice->getInvoiceType();
        $cuotaTotal = sprintf('%.2f', (float) $invoice->getTaxAmount());
        $importeTotal = sprintf('%.2f', (float) $invoice->getTotalAmount());
        $prevHash = $previous['hash'] ?? $invoice->getPreviousHash() ?? '';

        // 3. Generar huella — o usar la que el llamante ya almacenó.
        //
        // The stored huella is the value chained into the NEXT record and, once
        // filed, the one AEAT holds. Recomputing it here and quietly filing a
        // different number is how a chain forks, so a caller that has one hands
        // it over and the package files THAT, verbatim.
        $huella = $huella !== null && trim($huella) !== ''
            ? strtoupper(trim($huella))
            : $this->buildFingerprint(
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

        $this->reconcileTotals($detalle, $cuotaTotal, $importeTotal, $numSerie);

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
            $destinatarios,
            $subsanacion,
            $rechazoPrevio
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

    /**
     * File a RegistroAnulacion — the record that voids a previously issued
     * invoice.
     *
     * DELIBERATELY NOT NAMED cancelInvoice(). At least one integration already
     * subclasses AeatClient and defines its own
     * `cancelInvoice(App\Models\...\Invoice $invoice)`. A parent method taking
     * VeriFactuInvoice would make that override narrow the parameter type,
     * which PHP rejects outright — the application would stop loading the
     * moment it upgraded. This name is free, so both can coexist and an
     * application can move across when it chooses.
     *
     * ── SinRegistroPrevio: WHEN THE CALLER MUST SET IT ───────────────────────
     *
     * <SinRegistroPrevio>S</SinRegistroPrevio> declares that the alta being
     * annulled was never registered at AEAT. Without it, AEAT looks for the
     * alta, does not find it, and rejects the anulación as referring to nothing.
     *
     * Set it when the alta was never successfully filed:
     *
     *   - the alta submission failed at transport level and was never retried;
     *   - AEAT answered EstadoRegistro = "Incorrecto" for the alta (it is NOT
     *     registered, whatever the local status column says);
     *   - the invoice was issued while the system was offline and voided before
     *     the alta went out.
     *
     * Leave it off when AEAT accepted the alta — "Correcto", or
     * "AceptadoConErrores", which IS an acceptance. In the reference
     * integration that test is simply: did the alta come back with a CSV?
     *
     * The package cannot decide this for the caller: it does not know what AEAT
     * answered to a submission it did not make. Nothing is inferred and nothing
     * is defaulted; the element is omitted unless asked for.
     *
     * The anulación is its own link in the chain with its own huella. Pass the
     * stored one as $huella; when none is given it is computed with
     * HashHelper::generateCancellationHash(), which hashes the *Anulada field
     * names, not the alta's.
     *
     * @param VeriFactuInvoice $invoice The invoice being annulled
     * @param array|null $previous Previous registro for chaining: ['hash','number','date' (d-m-Y)]
     * @param string|null $huella File this fingerprint verbatim instead of computing one
     * @param string|null $generatedAt Replay this FechaHoraHusoGenRegistro instead of now()
     * @param bool $sinRegistroPrevio The alta was never registered at AEAT
     * @param string|null $rechazoPrevio 'S' when this anulación itself was rejected before
     * @return array
     */
    public function sendCancellation(
        VeriFactuInvoice $invoice,
        ?array $previous = null,
        ?string $huella = null,
        ?string $generatedAt = null,
        bool $sinRegistroPrevio = false,
        ?string $rechazoPrevio = null
    ): array {
        $this->assertVerifactuMode();

        $rechazoPrevio = $this->normaliseRechazoPrevio($rechazoPrevio, true, 'RegistroAnulacion');

        $issuer = $this->resolveIssuer($invoice);
        $issuerName = $issuer['name'];
        $issuerVat = $issuer['vat'];

        $ts = $generatedAt !== null && trim($generatedAt) !== ''
            ? trim($generatedAt)
            : \Carbon\Carbon::now('UTC')->format('c');

        $numSerie = (string) $invoice->getInvoiceNumber();
        $fechaExp = $invoice->getIssueDate()->format('d-m-Y');
        $prevHash = $previous['hash'] ?? '';

        $huella = $huella !== null && trim($huella) !== ''
            ? strtoupper(trim($huella))
            : HashHelper::generateCancellationHash([
                'issuer_tax_id' => $issuerVat,
                'invoice_number' => $numSerie,
                'issue_date' => $fechaExp,
                'previous_hash' => (string) $prevHash,
                'generated_at' => $ts,
            ])['hash'];

        // Schema order: IDVersion, IDFactura, RefExterna, SinRegistroPrevio,
        // RechazoPrevio, GeneradoPor, Generador, Encadenamiento,
        // SistemaInformatico, FechaHoraHusoGenRegistro, TipoHuella, Huella.
        // GeneradoPor/Generador are not built here — they describe an anulación
        // issued by someone other than the obligado, which this package has no
        // way to express.
        $registroAnulacion = [
            'IDVersion' => '1.0',
            // A cancellation names the invoice with the *Anulada element names.
            // The alta's names are rejected in this position.
            'IDFactura' => [
                'IDEmisorFacturaAnulada' => $issuerVat,
                'NumSerieFacturaAnulada' => $numSerie,
                'FechaExpedicionFacturaAnulada' => $fechaExp,
            ],
        ];

        if ($invoice->getExternalReference()) {
            $registroAnulacion['RefExterna'] = $invoice->getExternalReference();
        }

        if ($sinRegistroPrevio) {
            $registroAnulacion['SinRegistroPrevio'] = 'S';
        }

        if ($rechazoPrevio !== null) {
            $registroAnulacion['RechazoPrevio'] = $rechazoPrevio;
        }

        $registroAnulacion += [
            'Encadenamiento' => $this->buildChaining($previous, $issuerVat),
            'SistemaInformatico' => $this->buildSistemaInformatico($issuerName, $issuerVat),
            'FechaHoraHusoGenRegistro' => $ts,
            'TipoHuella' => '01',
            'Huella' => $huella,
        ];

        $body = [
            'Cabecera' => $this->buildHeader($issuerName, $issuerVat),
            'RegistroFactura' => [
                ['RegistroAnulacion' => $registroAnulacion],
            ],
        ];

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

    /**
     * <SistemaInformatico> — identical for an alta and an anulación.
     *
     * NombreRazon/NIF here describe the PRODUCER of the software, which is not
     * necessarily the obligado. They have always been filled with the issuer's
     * own details and that is left alone: changing who a registro says built it
     * is not a bug fix.
     */
    private function buildSistemaInformatico(string $issuerName, string $issuerVat): array
    {
        return [
            'NombreRazon' => $issuerName,
            'NIF' => $issuerVat,
            'NombreSistemaInformatico' => config('verifactu.sistema_informatico.name', 'LaravelVerifactu'),
            'IdSistemaInformatico' => config('verifactu.sistema_informatico.id', 'LV'),
            'Version' => config('verifactu.sistema_informatico.version', '1.0'),
            'NumeroInstalacion' => config('verifactu.sistema_informatico.installation_number', '001'),
            'TipoUsoPosibleSoloVerifactu' => config('verifactu.sistema_informatico.only_verifactu_capable', 'S'),
            'TipoUsoPosibleMultiOT' => config('verifactu.sistema_informatico.multi_obligated_entities_capable', 'N'),
            'IndicadorMultiplesOT' => config('verifactu.sistema_informatico.has_multiple_obligated_entities', 'N'),
        ];
    }

    private function buildBreakdowns(VeriFactuInvoice $invoice): array
    {
        $breakdowns = $invoice->getBreakdowns();
        $detalle = [];

        // null means "the obligado's configuration did not say", NOT "IVA" —
        // that distinction is what lets a row declare its own Impuesto without
        // ever overriding an outlet that has one configured.
        $taxType = $this->getBranchTaxType($invoice);

        foreach ($breakdowns as $breakdown) {
            $detalle[] = $this->buildBreakdownRow($breakdown, $taxType);
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

    /**
     * One <DetalleDesglose>.
     *
     * Schema order is Impuesto, ClaveRegimen, CalificacionOperacion |
     * OperacionExenta, TipoImpositivo, BaseImponibleOimporteNoSujeto,
     * CuotaRepercutida, TipoRecargoEquivalencia, CuotaRecargoEquivalencia, and
     * that is the order below.
     *
     * A row that is subject and not exempt (S1/S2) with no surcharge produces
     * exactly the six elements it always produced, with the same values. Every
     * branch below is entered only by a row that says something the old
     * five-getter contract had no way to say.
     */
    private function buildBreakdownRow($breakdown, ?string $taxType): array
    {
        $rateRaw = $breakdown->getTaxRate();

        if (is_string($rateRaw)) {
            $rateRaw = str_replace(',', '.', trim($rateRaw));
        }
        $rate = round((float) $rateRaw, 2);

        $row = [
            'Impuesto' => $this->resolveImpuesto($breakdown, $taxType),
            'ClaveRegimen' => $this->scalar($breakdown->getRegimeType()),
        ];

        $calificacion = strtoupper($this->scalar($breakdown->getOperationType()));
        $exenta = $this->exemptionCause($breakdown, $calificacion);

        if ($exenta !== null) {
            // CalificacionOperacion and OperacionExenta occupy the same slot and
            // are mutually exclusive. Writing CalificacionOperacion
            // unconditionally is what filed an exempt supply as an E-code in a
            // field whose only legal values are S1/S2/N1/N2.
            $row['OperacionExenta'] = $exenta;
        } else {
            $row['CalificacionOperacion'] = $calificacion;
        }

        // A row that is exempt, or not subject at all, has no tipo and no cuota:
        // AEAT's validations reject TipoImpositivo and CuotaRepercutida on both.
        // Filing 0.00 in them is not a neutral choice — it asserts a taxed
        // supply that happens to bear no tax.
        //
        // NOTE: that this is a hard rejection rather than a warning is taken
        // from the AEAT validation table and has not been confirmed against a
        // live submission. Omitting the elements is safe under either reading;
        // sending them is only safe under one.
        $taxed = $exenta === null && !in_array($calificacion, ['N1', 'N2'], true);

        if ($taxed) {
            $row['TipoImpositivo'] = $rate;
        }

        $row['BaseImponibleOimporteNoSujeto'] = sprintf('%.2f', (float) $breakdown->getBaseAmount());

        if ($taxed) {
            $row['CuotaRepercutida'] = sprintf('%.2f', (float) $breakdown->getTaxAmount());

            $surcharge = $this->surcharge($breakdown);

            if ($surcharge !== null) {
                // Both halves or neither: AEAT will not take a rate without its
                // cuota. They are emitted only when the row actually carries a
                // surcharge, so a bill outside the regime is byte-identical.
                $row['TipoRecargoEquivalencia'] = $surcharge['rate'];
                $row['CuotaRecargoEquivalencia'] = sprintf('%.2f', $surcharge['amount']);
            }
        }

        return $row;
    }

    /**
     * <Impuesto> for a row: the obligado's configured tax, else the row's own.
     *
     * The configured answer keeps priority, so an outlet that resolves IVA/IGIC
     * for itself sees no change. Only when it says nothing at all — which for
     * this package used to mean a silent fallback to '01' — does the row get
     * asked, and an unrecognised answer still falls back to '01'.
     */
    private function resolveImpuesto($breakdown, ?string $taxType): string
    {
        if ($taxType !== null) {
            return $taxType === 'IGIC' ? TaxType::IGIC->value : TaxType::VAT->value;
        }

        return $this->breakdownTaxType($breakdown) ?? TaxType::VAT->value;
    }

    /** A row's own Impuesto code, accepting '01'/'03'/… or 'IVA'/'IGIC'/'IPSI'. */
    private function breakdownTaxType($breakdown): ?string
    {
        if (!method_exists($breakdown, 'getTaxType')) {
            return null;
        }

        $declared = strtoupper($this->scalar($breakdown->getTaxType()));

        if ($declared === '') {
            return null;
        }

        $named = [
            'IVA' => TaxType::VAT->value,
            'IPSI' => TaxType::IPSI->value,
            'IGIC' => TaxType::IGIC->value,
            'OTHER' => TaxType::OTHER->value,
            'OTROS' => TaxType::OTHER->value,
        ];

        if (isset($named[$declared])) {
            return $named[$declared];
        }

        return TaxType::tryFrom($declared) instanceof TaxType ? $declared : null;
    }

    /**
     * <OperacionExenta> for a row, or null when it is not exempt.
     *
     * An explicit getExemptionCause() wins. Failing that, an E-code coming out
     * of getOperationType() is taken at face value — that is where an
     * application storing AEAT's own vocabulary naturally puts it, and E1..E6
     * are unambiguous: they are not valid anywhere else in the row.
     */
    private function exemptionCause($breakdown, string $calificacion): ?string
    {
        if (method_exists($breakdown, 'getExemptionCause')) {
            $declared = strtoupper($this->scalar($breakdown->getExemptionCause()));

            if (preg_match('/^E[1-6]$/', $declared) === 1) {
                return $declared;
            }
        }

        return preg_match('/^E[1-6]$/', $calificacion) === 1 ? $calificacion : null;
    }

    /**
     * The recargo de equivalencia for a row, or null when there is none.
     *
     * Duck-typed on getSurchargeRate()/getSurchargeAmount()
     * (VeriFactuBreakdownExtras), so a breakdown implementing only the original
     * five getters can never grow a surcharge it did not ask for. A rate and an
     * amount that are both zero are "no surcharge", not "a surcharge of nothing".
     *
     * @return array{rate: float, amount: float}|null
     */
    private function surcharge($breakdown): ?array
    {
        $rate = method_exists($breakdown, 'getSurchargeRate')
            ? round((float) $breakdown->getSurchargeRate(), 2)
            : 0.0;

        $amount = method_exists($breakdown, 'getSurchargeAmount')
            ? round((float) $breakdown->getSurchargeAmount(), 2)
            : 0.0;

        if ($rate == 0.0 && $amount == 0.0) {
            return null;
        }

        return ['rate' => $rate, 'amount' => $amount];
    }

    /**
     * CuotaTotal and ImporteTotal against the rows that are supposed to add up
     * to them.
     *
     * Deliberately a WARNING and not a correction. Both numbers are inputs to
     * the huella: the caller hashed them, chained the result into the next
     * record and may have already filed it. Quietly substituting a recomputed
     * total here would file an ImporteTotal that no longer matches the
     * fingerprint the application stored — trading a rejection AEAT explains
     * for a chain break it does not.
     *
     * AEAT's own rule, and what this checks:
     *   ImporteTotal = Σ(BaseImponibleOimporteNoSujeto + CuotaRepercutida
     *                    + CuotaRecargoEquivalencia)
     *
     * UNVERIFIED: whether CuotaTotal is likewise Σ(CuotaRepercutida +
     * CuotaRecargoEquivalencia) or only Σ(CuotaRepercutida). The former is the
     * documented reading and is what is checked; an application that puts only
     * the cuota in CuotaTotal will see this warning on surcharged bills, and
     * should confirm against the validation table before changing anything.
     */
    private function reconcileTotals(array $detalle, string $cuotaTotal, string $importeTotal, string $numSerie): void
    {
        $base = 0.0;
        $cuota = 0.0;
        $recargo = 0.0;

        foreach ($detalle as $row) {
            $base += (float) ($row['BaseImponibleOimporteNoSujeto'] ?? 0);
            $cuota += (float) ($row['CuotaRepercutida'] ?? 0);
            $recargo += (float) ($row['CuotaRecargoEquivalencia'] ?? 0);
        }

        $expectedCuota = round($cuota + $recargo, 2);
        $expectedImporte = round($base + $cuota + $recargo, 2);

        if (abs($expectedImporte - (float) $importeTotal) > 0.01) {
            Log::warning('VeriFactu: ImporteTotal does not match the desglose; AEAT will reject this registro.', [
                'invoice_number' => $numSerie,
                'importe_total' => $importeTotal,
                'desglose_total' => $expectedImporte,
                'base' => round($base, 2),
                'cuota' => round($cuota, 2),
                'recargo' => round($recargo, 2),
            ]);
        }

        if (abs($expectedCuota - (float) $cuotaTotal) > 0.01) {
            Log::warning('VeriFactu: CuotaTotal does not match the desglose. If this invoice carries a '
                . 'recargo de equivalencia, CuotaTotal is understood to include it.', [
                    'invoice_number' => $numSerie,
                    'cuota_total' => $cuotaTotal,
                    'desglose_cuota' => $expectedCuota,
                    'recargo' => round($recargo, 2),
                ]);
        }
    }

    /** A getter's answer as a trimmed string, unwrapping a backed enum. */
    private function scalar($value): string
    {
        if ($value instanceof \BackedEnum) {
            return trim((string) $value->value);
        }

        return trim((string) ($value ?? ''));
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

        $accepted = self::VAT_PREFIX_OVERRIDES[$country] ?? [$country];

        $carriesVatPrefix = false;
        foreach ($accepted as $candidate) {
            if (str_starts_with($prefix, $candidate)) {
                $carriesVatPrefix = true;
                break;
            }
        }

        $looksLikeEuVat = in_array($country, self::EU_VAT_COUNTRIES, true)
            && $carriesVatPrefix
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
        ?array $destinatarios,
        bool $subsanacion = false,
        ?string $rechazoPrevio = null
    ): array {
        // Keys are inserted in the order the RegistroAlta schema declares its
        // sequence. PHP's WSDL-mode SoapClient looks each element up by name and
        // serialises in schema order regardless, so this changes no byte of the
        // XML — it just stops the array from reading as though RefExterna and
        // Destinatarios came after the Huella, which is where they were being
        // appended.
        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura' => $issuerVat,
                'NumSerieFactura' => $numSerie,
                'FechaExpedicionFactura' => $fechaExp,
            ],
        ];

        if ($invoice->getExternalReference()) {
            $registroAlta['RefExterna'] = $invoice->getExternalReference();
        }

        $registroAlta['NombreRazonEmisor'] = $issuerName;

        // <Subsanacion> — "this record corrects one previously generated under
        // the same IDFactura". Emitted only as 'S': the element is optional and
        // its absence already means "no", so an ordinary alta keeps the exact
        // shape it has today rather than gaining a new <Subsanacion>N</>.
        if ($subsanacion) {
            $registroAlta['Subsanacion'] = 'S';

            if ($rechazoPrevio !== null) {
                $registroAlta['RechazoPrevio'] = $rechazoPrevio;
            }
        }

        $registroAlta += [
            'TipoFactura' => $tipoFactura,
            'DescripcionOperacion' => $invoice->getOperationDescription(),
            'Desglose' => ['DetalleDesglose' => $detalle],
            'CuotaTotal' => $cuotaTotal,
            'ImporteTotal' => $importeTotal,
            'Encadenamiento' => $encadenamiento,
            'SistemaInformatico' => $this->buildSistemaInformatico($issuerName, $issuerVat),
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

        // The reference to the invoice being corrected. MANDATORY on R1-R5 and
        // never emitted before this: TipoRectificativa said a correction was
        // happening while nothing said WHAT was corrected, so every rectificativa
        // went out unable to be matched to its original.
        if ($this->isCorrectiveInvoice($tipoFactura)) {
            $facturasRectificadas = $this->buildRectifiedInvoices($invoice, $issuerVat);
            if ($facturasRectificadas) {
                $registroAlta['FacturasRectificadas'] = $facturasRectificadas;
            }
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

        if ($destinatarios) {
            $registroAlta['Destinatarios'] = $destinatarios;
        }

        return $registroAlta;
    }

    /**
     * <RechazoPrevio>, validated.
     *
     * TWO THINGS ARE CERTAIN and are enforced here:
     *   - the element may only accompany Subsanacion = 'S';
     *   - it is a single-letter code, not free text.
     *
     * WHAT THE CODES MEAN IS NOT FULLY VERIFIED. The AEAT documentation for
     * RegistroAlta lists 'S' and 'X' (and 'N' for the negative case), and the
     * reading this package works from is:
     *
     *   'N'  the record being corrected WAS registered — an ordinary correction.
     *   'S'  it was previously rejected and is being re-filed.
     *   'X'  it was rejected by the AEAT itself and therefore does not appear in
     *        their register at all.
     *
     * After an EstadoRegistro = "Incorrecto" the value wanted is 'X' on that
     * reading. This has NOT been checked against a live AEAT validation run, so
     * the choice is left entirely to the caller: nothing is defaulted, nothing
     * is inferred from the invoice, and omitting it is legal (the element is
     * optional). Confirm the code against the current "Validaciones y errores"
     * table before relying on it in production.
     */
    private function normaliseRechazoPrevio(?string $rechazoPrevio, bool $subsanacion, string $registro): ?string
    {
        if ($rechazoPrevio === null || trim($rechazoPrevio) === '') {
            return null;
        }

        $value = strtoupper(trim($rechazoPrevio));

        if (!in_array($value, ['N', 'S', 'X'], true)) {
            throw new VeriFactuException(
                "RechazoPrevio must be 'N', 'S' or 'X'; got '{$rechazoPrevio}'."
            );
        }

        if ($registro === 'RegistroAlta' && !$subsanacion) {
            throw new VeriFactuException(
                'RechazoPrevio can only be sent on a RegistroAlta that is also a subsanación. '
                . 'Pass subsanacion: true, or drop rechazoPrevio.'
            );
        }

        if ($registro === 'RegistroAnulacion' && $value === 'X') {
            throw new VeriFactuException(
                "RechazoPrevio 'X' is a RegistroAlta value; a RegistroAnulacion takes 'S' or 'N'."
            );
        }

        return $value;
    }

    /**
     * NO-VERIFACTU ("requerimiento") mode is not implemented. Say so.
     *
     * Setting verifactu_mode = false used to change the SOAP endpoint and
     * nothing else, so the envelope that went to RequerimientoSOAP was an
     * ordinary VeriFactu one: no <RemisionRequerimiento>, no <RefRequerimiento>,
     * and — the part that cannot be bolted on — no XAdES-EPES signature over
     * the record, which is the whole point of the non-VeriFactu regime. AEAT
     * would reject it, and the operator would believe they were filing under a
     * requerimiento when they were not.
     *
     * Failing here is not a limitation of this change; it is the limitation
     * made visible.
     */
    private function assertVerifactuMode(): void
    {
        if ($this->verifactuMode) {
            return;
        }

        throw new VeriFactuException(
            'NO-VERIFACTU (requerimiento) mode is not implemented by this package. '
            . 'It requires RemisionRequerimiento/RefRequerimiento and an XAdES-EPES '
            . 'signature over each registro, neither of which is built here — sending '
            . 'anyway would file an envelope AEAT cannot accept while reporting success. '
            . 'Set verifactu.verifactu_mode = true, or file these records through a '
            . 'system that implements the requerimiento regime.'
        );
    }

    /**
     * <FacturasRectificadas> — which invoice(s) this corrective document corrects.
     *
     * Read through an optional getRectifiedInvoices() rather than a new method on
     * VeriFactuInvoice, because adding a required method to that contract would
     * fatal every application already implementing it.
     *
     * Each entry may be:
     *   - an array  ['number' => 'INV-1', 'date' => '2026-08-01'|Carbon, 'issuer' => 'NIF']
     *     (also accepts AEAT's own key names, and num_serie/fecha)
     *   - a bare string, i.e. just the number.
     *
     * A bare string CANNOT be emitted. FechaExpedicionFactura is mandatory inside
     * IDFacturaRectificada, and inventing a date would file a reference to an
     * invoice that does not exist on that date — worse than the omission. Those
     * entries are skipped and logged, loudly, because the caller needs to fix its
     * data rather than discover the gap at an inspection.
     *
     * @return array|null
     */
    private function buildRectifiedInvoices(VeriFactuInvoice $invoice, string $issuerVat): ?array
    {
        if (!method_exists($invoice, 'getRectifiedInvoices')) {
            return null;
        }

        $list = $invoice->getRectifiedInvoices();

        if ($list instanceof \Illuminate\Support\Collection) {
            $list = $list->all();
        }

        if (!is_array($list) || $list === []) {
            return null;
        }

        $entries = [];

        foreach ($list as $item) {
            $number = null;
            $date   = null;
            $issuer = $issuerVat;

            if (is_string($item) || is_numeric($item)) {
                $number = trim((string) $item);
            } elseif (is_array($item)) {
                $number = trim((string) ($item['number'] ?? $item['num_serie'] ?? $item['NumSerieFactura'] ?? ''));
                $date   = $item['date'] ?? $item['fecha'] ?? $item['FechaExpedicionFactura'] ?? null;
                $issuer = trim((string) ($item['issuer'] ?? $item['nif'] ?? $item['IDEmisorFactura'] ?? '')) ?: $issuerVat;
            }

            if ($number === null || $number === '') {
                continue;
            }

            $formatted = $this->formatRectifiedDate($date);

            if ($formatted === null) {
                Log::warning('VeriFactu: skipping a rectified-invoice reference with no usable issue date. '
                    . 'FechaExpedicionFactura is mandatory, so this correction will be filed without naming '
                    . 'the invoice it corrects.', [
                        'corrective_invoice' => $invoice->getInvoiceNumber(),
                        'rectified_number'   => $number,
                    ]);
                continue;
            }

            $entries[] = [
                'IDEmisorFactura'        => $issuer,
                'NumSerieFactura'        => $number,
                'FechaExpedicionFactura' => $formatted,
            ];

            // AEAT caps the list at 1000.
            if (count($entries) >= 1000) {
                break;
            }
        }

        return $entries === [] ? null : ['IDFacturaRectificada' => $entries];
    }

    /** AEAT wants d-m-Y. Accepts Carbon, d-m-Y, Y-m-d, or anything strtotime reads. */
    private function formatRectifiedDate($date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('d-m-Y');
        }

        $date = trim((string) ($date ?? ''));

        if ($date === '') {
            return null;
        }

        // Already in AEAT's format — do not let strtotime reinterpret it as m-d-Y.
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date) === 1) {
            return $date;
        }

        try {
            $parsed = \Carbon\Carbon::parse($date);
        } catch (\Throwable $e) {
            return null;
        }

        // Carbon does NOT throw on MySQL's zero date: '0000-00-00' parses
        // happily and formats as '30-11--0001', which would be filed to AEAT as
        // the issue date of the corrected invoice. A column left at the zero
        // date is missing data, so treat it as missing and let the caller skip
        // and warn rather than emit a date that cannot exist.
        if ((int) $parsed->format('Y') < 1000) {
            return null;
        }

        return $parsed->format('d-m-Y');
    }

    /**
     * Build ImporteRectificacion block for substitution corrective invoices
     *
     * @param VeriFactuInvoice $invoice
     * @return array|null
     */
    private function buildImporteRectificacion(VeriFactuInvoice $invoice): ?array
    {
        // Duck-typed, not contract members. Declaring them on VeriFactuInvoice
        // fatalled every application that had already implemented the interface
        // — including this package's own test suite, which has not been able to
        // build a VeriFactuInvoice since. An implementation that offers them
        // gets ImporteRectificacion; one that does not simply omits the block,
        // which is what it did before the feature existed.
        if (!method_exists($invoice, 'getCorrectedBaseAmount')
            || !method_exists($invoice, 'getCorrectedTaxAmount')) {
            return null;
        }

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
        $cuotaRecargo = method_exists($invoice, 'getCorrectedSurchargeAmount')
            ? $invoice->getCorrectedSurchargeAmount()
            : null;
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

    /**
     * Protected, not private, so the envelope can be inspected without a
     * certificate and without the network — which is the only way the shape of
     * a registro can be covered by a test at all.
     */
    protected function performSoapCall(
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

            // INFO says what happened. It no longer says WHO to.
            //
            // Every successful send used to write the full request and response
            // XML at info level: the customer's name and NIF, the line
            // breakdown and the amounts, into laravel.log, on a file with no
            // retention policy and none of the access controls the invoices
            // table has. The same records are already returned to the caller
            // (and, in the reference integration, stored in verifactu_logs),
            // so nothing is lost by keeping them out of the log file.
            Log::info('AEAT SOAP success', [
                'endpoint' => $location,
                'invoice_number' => $numSerie,
                'hash' => $huella,
                'first' => $previous ? false : true,
            ]);

            $this->logExchange('AEAT SOAP exchange', [
                'endpoint' => $location,
                'invoice_number' => $numSerie,
                'request_headers' => $lastRequestHeaders,
                'request_xml' => $lastRequestXml,
                'response_headers' => $lastResponseHeaders,
                'response_xml' => $lastResponseXml,
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

            // A fault still gets a loud, complete error line — minus the
            // payload, which goes to debug alongside the success path. The
            // fault code and string are what someone reading the log needs.
            Log::error('AEAT SOAP fault', [
                'endpoint' => $location,
                'invoice_number' => $numSerie,
                'error_message' => $e->getMessage(),
                'fault_code' => $e->faultcode ?? null,
                'fault_string' => $e->faultstring ?? null,
            ]);

            $this->logExchange('AEAT SOAP fault payload', [
                'endpoint' => $location,
                'invoice_number' => $numSerie,
                'request_headers' => $lastRequestHeaders,
                'request_xml' => $lastRequestXml,
                'response_headers' => $lastResponseHeaders,
                'response_xml' => $lastResponseXml,
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

    /**
     * The obligado's configured tax regime, or NULL when nothing says.
     *
     * Two changes, neither of which moves an outlet that has the row:
     *
     *  - it returns null rather than 'IVA' when the lookup finds nothing, so
     *    "not configured" can be told apart from "configured as IVA". Both
     *    still end at Impuesto '01' unless the breakdown itself declares one.
     *
     *  - the query is guarded. `sys_config` is a table this package does not
     *    own or ship; on any installation that does not happen to have it, this
     *    threw a QueryException out of the middle of building an envelope. A
     *    missing optional lookup is not a reason to fail a filing.
     */
    /**
     * The raw SOAP exchange, at debug level and redacted.
     *
     * Off unless the application asks for debug logging, and even then the
     * identifying elements are masked, because "we only log it in debug" tends
     * to mean "we log it in production with debug left on". Set
     * verifactu.logging.xml = false to suppress the payload entirely; set
     * verifactu.logging.redact = false to get it verbatim, which is a
     * deliberate choice someone has to make.
     */
    private function logExchange(string $message, array $context): void
    {
        if (!config('verifactu.logging.xml', true)) {
            return;
        }

        if (config('verifactu.logging.redact', true)) {
            foreach (['request_xml', 'response_xml'] as $key) {
                if (!empty($context[$key])) {
                    $context[$key] = $this->redactXml((string) $context[$key]);
                }
            }
        }

        Log::debug($message, $context);
    }

    /**
     * Mask the elements that identify a person inside a VeriFactu envelope.
     *
     * Names and tax identifiers only — amounts, dates, the huella and the
     * chaining stay readable, because those are what anyone is actually reading
     * the XML to debug. A masked value keeps its first and last character so
     * two different NIFs still look different.
     */
    private function redactXml(string $xml): string
    {
        $elements = ['NombreRazon', 'NombreRazonEmisor', 'NIF', 'ID'];

        foreach ($elements as $element) {
            $xml = preg_replace_callback(
                '/(<(?:\w+:)?' . $element . '>)(.*?)(<\/(?:\w+:)?' . $element . '>)/s',
                function (array $m): string {
                    return $m[1] . $this->mask($m[2]) . $m[3];
                },
                $xml
            ) ?? $xml;
        }

        return $xml;
    }

    /** 'A00000000' -> 'A*******0'. Short values disappear entirely. */
    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 1) . str_repeat('*', $length - 2) . mb_substr($value, -1);
    }

    private function getBranchTaxType(VeriFactuInvoice $invoice): ?string
    {
        $branchId = null;

        if ($invoice instanceof Model) {
            $branchId = $invoice->getAttribute('branch_id');
        }

        if (!$branchId) {
            return null;
        }

        try {
            $val = DB::table('sys_config')
                ->where('branch_id', $branchId)
                ->where('config_keys', 'tax_type')
                ->value('config_values');
        } catch (\Throwable $e) {
            Log::debug('VeriFactu: no sys_config tax_type lookup available; falling back to the breakdown.', [
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $val = strtoupper(trim((string) $val));

        return in_array($val, ['IVA', 'IGIC'], true) ? $val : null;
    }
}
