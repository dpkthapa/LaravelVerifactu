# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - Preparing for v2.0.0

### Added — Subsanación, anulación y desglose completo

- `AeatClient::sendInvoice()` acepta `subsanacion:` y `rechazoPrevio:` y emite
  `<Subsanacion>` / `<RechazoPrevio>`. Sin ellos, un registro rechazado por AEAT
  (`EstadoRegistro = "Incorrecto"`) no se podía corregir por ningún medio que
  ofreciera el paquete.
- `AeatClient::sendCancellation()` construye el `RegistroAnulacion`, incluido
  `<SinRegistroPrevio>`, necesario cuando el alta que se anula nunca llegó a
  registrarse. Nombre deliberado: `cancelInvoice()` está ocupado en integraciones
  que ya extienden el cliente.
- `HashHelper::generateCancellationHash()` — la anulación no hashea los mismos
  campos que el alta.
- `<TipoRecargoEquivalencia>` / `<CuotaRecargoEquivalencia>` por fila. Además de
  la aritmética, arregla la identidad de línea: AEAT distingue una línea por
  `(Impuesto, ClaveRegimen, CalificacionOperacion, TipoImpositivo,
  TipoRecargoEquivalencia)`.
- `<OperacionExenta>` (E1..E6) en lugar de `CalificacionOperacion` para filas
  exentas, y sin `TipoImpositivo` ni `CuotaRepercutida` en filas exentas o no
  sujetas (N1/N2).
- `VeriFactuBreakdownExtras` y el enum `ExemptionCause`. Ambos opcionales y
  leídos con `method_exists()`.
- `sendCancellation()` acepta el mismo `$record` (`hash` + `generated_at`) que
  `sendInvoice()` en v2.0.0, para presentar la huella ya almacenada en lugar de
  recalcular una distinta.
- El emisor por instancia de v2.0.0 se conserva como base y la factura puede
  sobreescribirlo: un worker de cola construye un cliente y sirve a todos los
  emisores, y la huella almacenada se calculó con el NIF que lleva la factura.
- Conciliación de `ImporteTotal` y `CuotaTotal` contra el desglose (aviso por
  log, nunca corrección silenciosa: son entradas de la huella).
- `verifactu.logging.{xml,redact}`.
- `phpunit.xml` y 36 pruebas nuevas del sobre.

### Fixed

- **BC**: `getCorrectedBaseAmount()`, `getCorrectedTaxAmount()` y
  `getCorrectedSurchargeAmount()` dejan de ser miembros obligatorios de
  `VeriFactuInvoice`. Añadirlos rompió toda implementación existente del
  contrato, incluidas las pruebas del propio paquete.
- `VeriFactuBreakdown::getTaxRate()` pasa a `float|string`: un cast
  `decimal:2` de Eloquent devuelve string y no podía satisfacer `float`.
- `buildFingerprint()` delega en `HashHelper`, que hace `trim()`. Un valor con un
  espacio de más producía una huella en el registro y otra en el envío, y el
  registro siguiente encadenaba con la primera.
- El emisor sale de la propia factura cuando la lleva
  (`getIssuerTaxId()` / `issuer_tax_id`), no de `config('verifactu.issuer')`, que
  es la respuesta de hoy y no la que se usó para calcular la huella almacenada.
- El XML completo de petición y respuesta dejaba de escribirse en `laravel.log`
  a nivel `info` en cada envío: ahora `debug`, y enmascarado.
- El modo NO VERIFACTU (requerimiento) lanza excepción en lugar de enviar un
  sobre sin `RemisionRequerimiento`, `RefRequerimiento` ni firma XAdES.
- La consulta a `sys_config` (tabla que el paquete no distribuye) va protegida;
  antes lanzaba `QueryException` desde dentro del constructor del sobre en
  cualquier instalación que no la tuviera.


### Added

#### Host-chain & multi-tenant support (Quandium integration)
- `AeatClient::sendInvoice()` acepta un tercer parámetro `$record` con
  `['hash' => ..., 'generated_at' => ...]` precalculados. Imprescindible cuando
  la aplicación host persiste su propia cadena de huellas en el momento de la
  emisión: la `Huella` y `FechaHoraHusoGenRegistro` remitidas a AEAT deben ser
  exactamente las almacenadas, nunca recalculadas en el envío asíncrono
  (recalcularlas con un timestamp nuevo rompería la integridad de la cadena).
- `AeatClient` acepta un emisor por instancia (5º parámetro del constructor,
  `['name' => ..., 'vat' => ...]`) para aplicaciones multi-tenant donde cada
  empresa es un obligado de emisión distinto. Sin él, se mantiene el
  comportamiento anterior (`config('verifactu.issuer')`).

#### Colaborador social / entidad colaboradora AEAT
- Soporte de **Representante** en la Cabecera: plataformas registradas como
  entidad colaboradora remiten en nombre de sus clientes usando el certificado
  de la PLATAFORMA — los obligados de emisión no necesitan aportar el suyo.
  Se configura globalmente (`config('verifactu.representative')` /
  `VERIFACTU_REP_NAME`, `VERIFACTU_REP_VAT`) o por instancia (6º parámetro del
  constructor de `AeatClient`). Si no se configura, la Cabecera no cambia.

#### API de integración para hosts
- Contrato `CertificateProvider` + `ConfigCertificateProvider` (por defecto,
  lee `verifactu.aeat.cert_path/cert_password` y valida que el fichero exista).
  Los hosts con otro almacén de certificados (por emisor, Vault, S3+KMS...)
  re-vinculan el contrato en el contenedor.
- `AeatClientFactory`: construye `AeatClient` desde config + CertificateProvider,
  con emisor/representante por llamada. El transport de un host queda reducido a
  mapear su propio registro de cadena.
- `AeatSubmissionResponse`: DTO tipado sobre el array crudo de `sendInvoice()`.
  Parsea `EstadoEnvio`, `CSV` y `RespuestaLinea` (única o lista) con
  `accepted()` / `partiallyAccepted()` / `rejected()` / `firstError()` /
  `toArray()` — antes cada consumidor escarbaba el stdClass del SOAP a mano.
- Bindings registrados en `VeriFactuServiceProvider` (`CertificateProvider` →
  `ConfigCertificateProvider`, `AeatClientFactory` singleton).

### Changed
- `AeatClient::buildFingerprint()` delega en `HashHelper::generateInvoiceHash()`:
  una única implementación de la especificación de huella AEAT v0.1.2 (antes
  estaba duplicada y solo `HashHelper` tenía tests de conformidad).

### Fixed
- Tests `AeatClientHybridTest` y `ContractComplianceTest`: las implementaciones
  anónimas de `VeriFactuInvoice` no compilaban tras añadirse los métodos
  `getCorrectedBaseAmount/TaxAmount/SurchargeAmount` al contrato.

#### Core Features (Issue #6 + PR #8)
- Soporte para dos modos de facturación: VERIFACTU y NO VERIFACTU (Requerimiento)
- Nuevo parámetro de configuración `verifactu_mode` para alternar entre modos
- URLs dinámicas del servicio SOAP según el modo configurado
- Helper `QrUrlHelper` para generar URLs de códigos QR de facturas
- Cliente AEAT con comunicación SOAP/XML completa
- Validación completa de respuestas AEAT (EstadoEnvio, EstadoRegistro, CSV)

#### Database Fields
- Campo `csv` para código de verificación AEAT (16 caracteres)
- Soporte completo para encadenamiento blockchain de facturas:
  - `previous_invoice_number`, `previous_invoice_date`, `previous_invoice_hash`
  - `is_first_invoice` (boolean)
- Campos para facturas rectificativas:
  - `rectificative_type` (I=Por diferencia, S=Por sustitución)
  - `rectified_invoices` (JSON array)
  - `rectification_amount` (JSON)
- Campos para subsanación de facturas rechazadas:
  - `is_subsanacion`, `rejected_invoice_number`, `rejection_date`
- Campo `numero_instalacion` para soporte multi-tenant (máx. 100 caracteres)
- Campos de estado AEAT:
  - `aeat_estado_registro` (Correcto, AceptadoConErrores, Incorrecto)
  - `aeat_codigo_error`, `aeat_descripcion_error`
  - `has_aeat_warnings` (boolean)
- Campo `operation_date` (fecha de operación distinta a expedición)
- Campo `id_type` en recipients para identificación de extranjeros (02-07)
- Campos `customer_name` y `customer_tax_id` ahora nullable (facturas simplificadas F2)
- Campos `tax_rate` y `tax_amount` en breakdowns ahora nullable (operaciones N1/N2 y E1-E6)

#### System Configuration
- Estructura `sistema_informatico` con campos configurables:
  - `name`: Nombre del sistema informático
  - `id`: Identificador del sistema (2-30 caracteres)
  - `version`: Versión del sistema
  - `installation_number`: Número de instalación único
  - `only_verifactu_capable`: Sistema solo puede usarse en modo VERIFACTU (S/N)
  - `multi_obligated_entities_capable`: Sistema soporta múltiples obligados tributarios (S/N)
  - `has_multiple_obligated_entities`: Indica si existen múltiples obligados (S/N)

#### Tests & Validation
- Tests unitarios para `QrUrlHelper` (6 tests)
- Tests unitarios para modos del `AeatClient` (5 tests)
- Archivo `.env.example` con todas las variables de configuración en inglés
- Documentación completa sobre los nuevos modos y configuraciones

#### Database Optimizations
- Índice único compuesto `(issuer_tax_id, number)` para soporte multi-tenant
- Índices optimizados para queries multi-tenant:
  - `issuer_tax_id` (consultas por emisor)
  - `(issuer_tax_id, date)` (facturas por fecha de un cliente)
  - `(issuer_tax_id, previous_invoice_number)` (búsqueda de encadenamiento)

### Changed
- `AeatClient` ahora acepta un parámetro opcional `$verifactuMode` en el constructor
- Los parámetros del `SistemaInformatico` ahora se obtienen de la configuración en lugar de valores fijos
- Configuración unificada en estructura anidada `sistema_informatico` con nombres en inglés
- Variables de entorno renombradas a inglés para mejor mantenibilidad:
  - `VERIFACTU_SYSTEM_NAME`, `VERIFACTU_SYSTEM_ID`, `VERIFACTU_SYSTEM_VERSION`
  - `VERIFACTU_INSTALLATION_NUMBER`
  - `VERIFACTU_ONLY_VERIFACTU_CAPABLE`
  - `VERIFACTU_MULTI_OT_CAPABLE`
  - `VERIFACTU_HAS_MULTI_OT`
- Actualizado el README con ejemplos de uso del helper QR y configuración de modos

### Fixed
- URLs del servicio SOAP ahora se ajustan correctamente según el entorno (producción/pruebas) y modo (VERIFACTU/NO VERIFACTU)
- Índice único de invoices ahora es compuesto para permitir mismo número entre diferentes emisores

## [1.0.0] - 2024-XX-XX

### Added
- Versión inicial del paquete LaravelVerifactu
- Modelos Eloquent para Invoice, Breakdown y Recipient
- Enums para tipos fiscales (InvoiceType, TaxType, RegimeType, OperationType)
- Helpers para fecha, string y hash
- Servicio AeatClient para comunicación SOAP con AEAT
- Form Requests para validación
- API Resources para respuestas RESTful
- Factories y tests unitarios
- Comando artisan para generación de adaptadores
- Sistema de contratos para integración con sistemas existentes
