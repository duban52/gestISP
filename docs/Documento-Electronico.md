# El documento electrónico (fase 10)

Qué se construyó para que una factura electrónica exista de verdad: su
XML, sus tres valores calculados y su firma.

Basado en el **Anexo Técnico de Factura Electrónica de Venta v1.9**
(Resolución 000165 de 2023). Todo lo que dice este documento se
comprobó leyendo el anexo o los XML de ejemplo del paquete oficial, no
de memoria.

---

## Qué había antes y qué faltaba

Al terminar la fase 9 el sistema ya sabía **decidir** si una factura era
electrónica, y la numeraba de un rango autorizado por la DIAN. Pero ahí
se acababa: las tablas `electronic_documents`, `dian_certificates` y
`dian_configurations` existían y **nadie escribía en ellas**. El CUFE se
sabía calcular y no lo calculaba nadie.

Una factura electrónica salía con su número correcto y sin ningún
documento detrás.

## Qué hace ahora

Al emitir una factura electrónica, el listener `GenerateElectronicDocument`
—enganchado a `InvoiceIssued`— produce su documento:

```
Factura emitida (document_kind = electronic)
        │
        ├── InvoiceXmlBuilder      → el XML UBL 2.1 con sts:DianExtensions
        │      ├── CufeCalculator          → el CUFE (SHA-384)
        │      ├── SoftwareSecurityCode    → la huella del software (SHA-384)
        │      └── QrContent               → el QR y su URL de consulta
        │
        ├── XadesSigner            → la firma, si hay certificado vigente
        │
        └── electronic_documents   → todo guardado, con su estado
```

## Los cuatro valores calculados

| Valor | Fórmula | Dónde está |
|---|---|---|
| **CUFE** | `SHA-384` de 14 campos + la clave técnica | `CufeCalculator` |
| **Código de seguridad del software** | `SHA-384(IdSoftware + PIN + NroDocumento)` (§11.8) | `SoftwareSecurityCode` |
| **QR** | Etiquetas fijas + URL del catálogo (§11.7) | `QrContent` |
| **Firma** | XAdES-EPES, RSA-SHA256 sobre tres referencias (§10) | `XadesSigner` |

### El CUFE se pudo verificar; los demás no

El anexo publica en §11.2.1 un **ejemplo resuelto** del CUFE: datos de
entrada y hash esperado. `CufeCalculatorTest` lo reproduce exactamente.
Es la única verificación de extremo a extremo posible sin una
habilitación real.

Del código de seguridad del software no hay ejemplo —tendría que revelar
un PIN—, así que solo se defiende la forma: la concatenación del anexo,
que sean 96 caracteres y que cambiar cualquier dato cambie el resultado.

## Las trampas del anexo (cuidado al leerlo)

Tres cosas del documento oficial son incorrectas o inconsistentes. Están
anotadas en el código para que nadie las copie:

1. **El guion del huso horario.** La cadena de ejemplo del CUFE aparece
   impresa con la hora como `10:53:1005:00` en vez de
   `10:53:10-05:00`. Copiada literalmente da un hash distinto del que el
   mismo documento da por bueno. Solo la versión **con** guion cuadra.

2. **El ejemplo del QR.** Trae la fecha como `2019-16-01` —mes 16— y un
   CUFE de 64 caracteres, o sea SHA-256, cuando el CUFE que especifica
   §11.2 es SHA-384 de 96. Son restos de una versión anterior. Lo que
   vale es la lista de campos, no ese ejemplo.

3. **La ruta de la política de firma.** El texto del anexo dice
   `.../politicadefirma/v2/politicadefirmav2.pdf`; sus propios XML
   firmados usan `.../politicadefirma/v1/politicadefirmav2.pdf`. En
   `XadesSigner` está la del ejemplo, **y hay que confirmarla contra la
   política publicada antes de emitir en producción**, junto con su
   resumen: si no coinciden, la DIAN rechaza la firma.

## Cómo se comprueba que el XML está bien

Los 20 esquemas XSD de la DIAN están versionados en
`resources/dian/xsd`. No es adorno: **en UBL el orden de los elementos
es parte del contrato**, y un XML con los mismos datos en distinto orden
es inválido — algo que no se ve leyéndolo, solo cuando la DIAN lo
rechaza.

`InvoiceXmlTest` valida el documento generado contra
`UBL-Invoice-2.1.xsd`, y `erroresDeEsquema()` permite volver a validar
justo antes de transmitir.

Los esquemas se copiaron al repositorio por la misma razón que los
catálogos: la carpeta `ayuda facturacion dian` está en `.gitignore` y no
existe en el servidor.

## La firma

La estructura no salió de la teoría de XAdES —que admite muchas
formas— sino de un **XML firmado por la propia DIAN** de los que trae el
paquete. Se reprodujo elemento por elemento.

Se firman **tres** cosas, y las tres van como referencia dentro de
`SignedInfo`:

1. El documento entero, con la firma excluida.
2. El `KeyInfo`, donde va la clave pública.
3. Las `SignedProperties`: hora de firma, certificado y política.

Firmar solo el documento dejaría cambiar la hora de firma o el
certificado sin invalidar nada.

### El error que se come a todo el mundo

Los resúmenes se calculan sobre los nodos **ya insertados en el
documento**. La canonicalización arrastra las declaraciones de espacios
de nombres que el nodo hereda de sus padres: un `SignedProperties`
canonicalizado por su cuenta da un resumen distinto del que da dentro
del documento, y la firma no cuadra sin ninguna pista de por qué.

Por eso `XadesSigner` arma primero la estructura entera con los
resúmenes en blanco, la inserta, y solo entonces los calcula.
`XadesSignatureTest` lo comprueba recalculándolos.

### Qué se puede verificar sin certificado real

Se firma con un certificado autofirmado generado al vuelo, y se
comprueba: que la firma **verifica** con su clave pública, que los tres
resúmenes se recalculan igual, que tocar el documento la rompe, y que el
documento firmado **sigue validando contra el XSD**.

La DIAN rechazaría ese certificado por **política** —no lo emite una
entidad acreditada—, no por mecánica. Eso es lo único que queda por ver
el día que haya un `.p12` de verdad.

## Cuando algo falla, la factura no se cae

Es la regla que manda en `ElectronicDocumentGenerator`. La factura **ya
está emitida**: tiene su número, ya gastó un consecutivo autorizado y el
cliente ya tiene el servicio. Que falte el municipio del cliente o que
la resolución haya vencido no puede deshacer nada de eso ni reventar la
corrida mensual a mitad.

El fallo se atrapa y se **anota**: queda una fila en borrador con el
motivo en `last_error`. Es la diferencia entre «algo falló» y saberlo
sin ir a leer los logs del servidor. Reintentar completa esa misma fila,
no crea otra.

## Estados del documento

| Estado | Qué significa |
|---|---|
| `draft` | Falló la generación. `last_error` dice por qué |
| `generated` | XML y CUFE listos, **sin firmar** (no hay certificado vigente) |
| `signed` | Firmado, listo para transmitir |
| `sent` / `accepted` / `rejected` | Fase 11 |

Que un documento se quede en `generated` no es un error: es lo normal
mientras la empresa no tenga su certificado cargado.

## Lo que NO está hecho

- **IVA exento y excluido.** Un ISP colombiano factura internet
  residencial de estratos 1, 2 y 3 sin IVA, y en el XML eso no es
  «porcentaje cero»: son estructuras propias con su código de motivo de
  exención. Hoy las líneas sin impuesto simplemente no suman al
  `TaxTotal` — correcto para lo gravado, **incorrecto para lo exento**.
  Hace falta antes de facturar electrónicamente a esos estratos.
- **Pantallas de administración DIAN.** La configuración, el
  certificado, las resoluciones y los rangos solo se pueden crear por
  consola. No hay UI.
- **Notas crédito y débito electrónicas.** Siguen saliendo siempre por
  la serie interna, aunque corrijan una factura electrónica. La decisión
  D6 del plan dice que deberían seguir el camino de la factura que
  corrigen.
- **La transmisión.** Es la fase 11.

## Para la fase 11 (transporte)

Lo que se averiguó por el camino, leyendo el anexo:

- El método de envío se llama **`SendBillSync`** (§12.1).
- **Política de reintentos** que exige el anexo (§12.2, §12.4): ante
  error, reintentar a los 5 segundos, y dos veces más cada 5 segundos;
  a los 15 segundos sin respuesta se expide sin validación previa. Ante
  «time out», reintentar a los 2 minutos, cuatro veces más; si persiste,
  contingencia tipo 04.
- **Bloqueos conocidos:** el paquete oficial **no trae WSDL**, así que
  los endpoints exactos, nombres de operación y autenticación siguen sin
  confirmar; y `php -m` **no incluye `soap`**, así que los sobres habría
  que armarlos a mano sobre Guzzle (sí están `dom`, `openssl`,
  `xmlwriter`).
- URLs de consulta del catálogo, ya en el código:
  - Producción: `https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=`
  - Habilitación: `https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=`

## Dónde está cada cosa

| Pieza | Archivo |
|---|---|
| CUFE | `app/Billing/Dian/CufeCalculator.php` |
| Código del software | `app/Billing/Dian/SoftwareSecurityCode.php` |
| QR | `app/Billing/Dian/QrContent.php` |
| XML UBL | `app/Billing/Dian/InvoiceXmlBuilder.php` |
| Firma XAdES | `app/Billing/Dian/XadesSigner.php` |
| Orquestación | `app/Billing/Dian/ElectronicDocumentGenerator.php` |
| Enganche | `app/Listeners/GenerateElectronicDocument.php` |
| Esquemas XSD | `resources/dian/xsd/` |

Pruebas: `tests/Unit/Dian/` (los cálculos, sin base de datos) y
`tests/Feature/Billing/InvoiceXmlTest.php`,
`ElectronicDocumentGenerationTest.php`, `XadesSignatureTest.php`.
