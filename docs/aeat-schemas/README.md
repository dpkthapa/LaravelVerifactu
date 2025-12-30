# AEAT XML Schemas (XSD) - Documentación de Referencia

Este directorio contiene los esquemas oficiales de AEAT para el sistema VeriFactu.

## Archivos

### Schemas Principales

- **`SuministroLR.xsd`**: Esquema para el suministro de Libros Registro (operación RegFactuSistemaFacturacion)
- **`SuministroInformacion.xsd`**: Esquema con las estructuras de datos de facturas (RegistroAlta, IDFactura, Desglose, etc.)
- **`RespuestaSuministro.xsd`**: Esquema de respuesta tras envío de facturas
- **`ConsultaLR.xsd`**: Esquema para consultas de facturas
- **`RespuestaConsultaLR.xsd`**: Esquema de respuesta para consultas
- **`xmldsig-core-schema.xsd`**: Esquema de firma digital XML (XAdES)

### WSDL

- **`SistemaFacturacion.wsdl`**: Definición del servicio web SOAP de AEAT

## Propósito

Estos archivos se utilizan como **documentación de referencia** para:

1. **Validación durante desarrollo**: Verificar que nuestro XML cumple con la estructura oficial
2. **Tests unitarios**: Validar XML generado contra esquemas XSD
3. **Referencia de estructura**: Consultar campos obligatorios, tipos de datos, restricciones

## Importante

⚠️ **El `AeatClient` NO necesita estos archivos en runtime**. La implementación actual usa `DOMDocument` y `Laravel HTTP Client` para construir y enviar el XML directamente, sin necesidad de parsear WSDLs o validar contra XSD durante la ejecución.

## Fuente

Estos esquemas son oficiales de AEAT y pueden consultarse en:
- https://sede.agenciatributaria.gob.es/

## Uso en Tests

```php
// Ejemplo de validación en tests
$xsdPath = __DIR__ . '/../../docs/aeat-schemas/SuministroInformacion.xsd';
$dom = new DOMDocument();
$dom->loadXML($generatedXml);
$isValid = $dom->schemaValidate($xsdPath);
```

## Notas Técnicas

- Los XSD usan namespaces específicos de AEAT
- Algunos campos tienen restricciones de longitud y formato
- Las fechas deben estar en formato `dd-mm-yyyy`
- Los timestamps en formato ISO 8601 con timezone
- Los NIFs/CIFs españoles tienen validación de formato específica
