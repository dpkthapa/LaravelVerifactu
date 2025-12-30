# Test Fixtures

Este directorio contiene archivos de prueba para los tests unitarios.

## Archivos

### `test_cert.pem` (NO INCLUIDO EN REPO)

Certificado de prueba **NO VÁLIDO** para AEAT. Solo se usa para:
- Instanciar el `AeatClient` en tests unitarios
- Probar la carga de certificados
- Validar estructura de XML sin enviar a AEAT real

⚠️ **IMPORTANTE**: 
- Este certificado es **falso** y **no puede usarse** para comunicarse con AEAT
- Los archivos `.pem` están en `.gitignore` por seguridad
- Genera tu propio certificado de prueba si es necesario

## Uso en Tests

```php
$client = new AeatClient(
    certPath: __DIR__ . '/../fixtures/test_cert.pem',
    certPassword: null,
    production: false
);
```

## Certificados Reales

Para usar certificados reales en pruebas de integración:
1. Obtener certificado válido de AEAT
2. Configurar en `.env.testing`:
   ```
   VERIFACTU_CERT_PATH=/path/to/real/cert.pfx
   VERIFACTU_CERT_PASSWORD=your_password
   ```
3. Nunca commitear certificados reales al repositorio
