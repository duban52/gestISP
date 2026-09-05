# Transmisión a la DIAN (fase 11)

Cómo sale un documento hacia la DIAN, qué pasa cuando algo falla, y por
qué casi todo esto se pudo construir y probar sin tener credenciales.

---

## El problema de partida

La fase 10 dejó documentos electrónicos correctos: XML válido contra el
XSD de la DIAN, con su CUFE y su firma. Pero no salían a ninguna parte.

Y para hacerlos salir faltaba algo que en ese momento no teníamos: la
URL del servicio y un certificado con el que autenticarse. *(La URL
resultó ser pública y ya viene puesta — ver más abajo. Lo que sigue
faltando es probarlo contra la DIAN de verdad.)*

## La decisión: una interfaz

Todo lo que rodea al envío se separó del envío mismo:

```
DocumentTransmitter            ← reintentos, estados, idempotencia, registro
        │
        └── DianTransport      ← la interfaz: "manda esto y dime qué pasó"
                ├── FakeDianTransport   ← no habla con nadie. Por defecto.
                └── SoapDianTransport   ← el real. Sin verificar.
```

El envío en sí es una línea. Lo que cuesta —y lo que se rompe— es lo de
alrededor, y **eso sí se puede construir y probar hoy**: 16 pruebas
cubren reintentos, estados, idempotencia y registro de intentos, sin
tocar la red.

El día que haya certificado y habilitación, la implementación real
entra en su sitio sin cambiar nada más.

## Las cuatro respuestas posibles

No son dos. Confundirlas es lo que hace que un sistema reintente lo que
no debe y se rinda con lo que sí:

| | Qué significa | ¿Se reintenta? |
|---|---|---|
| **Aceptado** | La DIAN la validó | No. Es definitivo |
| **Rechazado** | La recibió y dijo que no | **No.** Daría el mismo no |
| **Error** | Falló la comunicación | Sí, cada 5 segundos |
| **Demora** | No contestó a tiempo | Sí, cada 2 minutos |

Que el **rechazo no se reintente** es lo importante: es por el contenido
del documento, así que reintentarlo gasta intentos para recibir
exactamente el mismo no. Lo que hay que hacer es corregir y emitir otro.

## La cadencia la fija el anexo, y no es una sola

- **Ante error** (§12.2): reintentar a los 5 segundos, y dos veces más
  cada 5 segundos. A los 15 segundos sin arreglo, correspondería
  contingencia tipo 04.
- **Ante demora** (§12.4): la DIAN tarda más de un minuto. Reintentar a
  los 2 minutos, y cuatro veces más cada 2 minutos.

Está en `DocumentTransmitter::ESPERA_ERROR` / `ESPERA_DEMORA` y sus
contadores, con pruebas que fijan cada número.

## Idempotencia

Es la propiedad más importante de toda la fase, y por eso vive en
`DocumentTransmitter` y no en quien llama: **una cola puede repetir un
trabajo** —es su comportamiento normal ante un fallo del trabajador—,
así que la garantía tiene que estar donde se decide.

Un documento aceptado o rechazado no se vuelve a mandar. Punto. Aunque
alguien reencole el job, aunque la cola repita, aunque se llame dos
veces.

## Cada intento queda escrito

`document_transmissions`: una fila **por intento**, no por documento.

El anexo obliga a «mantener o archivar las evidencias del error»
(§12.2), y con una sola fila cada reintento pisaría al anterior. Además
es lo que permite contestar la pregunta que de verdad se hace cuando
algo va mal: *¿qué le mandamos y qué nos contestó?*

No se guarda el XML enviado —ya está en `electronic_documents`, es
grande y no cambia entre intentos—. Sí la respuesta, que es distinta
cada vez.

## Por qué en cola

Porque el anexo obliga a esperar **2 minutos** entre reintentos, hasta
cinco veces. Dormir eso dentro de la petición que emitió la factura
dejaría al usuario mirando una pantalla en blanco diez minutos, y la
corrida mensual —que emite cientos— sería imposible.

Cada intento es un job independiente que, si toca reintentar, se
reencola con su retraso. No hay bucles con `sleep`: un reintento a 2
minutos no ocupa un trabajador durante 2 minutos.

## Cuándo se transmite

Automáticamente al firmar, si hay URL para el ambiente del documento
—que la hay, porque vienen puestas de fábrica—.

Para barrer lo que quedó pendiente —porque la cola se cayó, o porque el
servicio de la DIAN estuvo caído:

```bash
php artisan dian:transmitir --dry-run
php artisan dian:transmitir
```

Encola, no transmite: la idempotencia sigue decidiendo. Correrlo dos
veces no duplica nada.

## El transporte SOAP real

⚠️ **Escrito pero no verificado contra el servicio real.** Lo que se
sabe viene de la «Guía Herramienta para el Consumo de Web Services» de
la DIAN:

- Es **SOAP 1.2**: la acción va como parámetro `action` dentro del
  `Content-Type`, no en una cabecera `SOAPAction`. *(Aquí ya hubo un
  bug: `withBody()` de Laravel pisa el Content-Type puesto con
  `withHeaders()`, y el `action` se perdía. Lo encontró una prueba.)*
- Autenticación con **WS-Security, firma X.509**, usando el **mismo
  certificado** con el que se firma la factura.
- **WS-Addressing** (`wsa:To`, `wsa:Action`) y un `Timestamp` con
  vigencia.
- El método es **`SendBillSync`**, y recibe el XML **dentro de un ZIP en
  base64**.

### La firma del sobre NO es la de la factura

Se parecen y no lo son. Confundirlas cuesta días:

| | Factura (XAdES) | Sobre SOAP (WS-Security) |
|---|---|---|
| Canonicalización | **inclusiva** (C14N) | **exclusiva** (exc-c14n) |
| Qué firma | documento, KeyInfo, SignedProperties | Timestamp, `wsa:To`, Body |
| La clave pública | en `ds:X509Certificate` | en un `BinarySecurityToken` |
| Para qué | que el documento perdure | autenticar ESTA petición; caduca |

### Qué sí se pudo comprobar

Interceptando la petición antes de que salga: que el sobre es XML bien
formado, que la firma WS-Security **verifica** con su propia clave
pública, que el documento viaja comprimido, y —el más importante— que
**un 200 con `IsValid=false` se interpreta como RECHAZO**. La DIAN
contesta 200 aunque haya rechazado: tratar cualquier 200 como aceptado
dejaría documentos marcados como validados que no lo están.

## Por qué no se usa `ext-soap`

Porque no está instalada, y porque aunque lo estuviera `SoapClient` no
firma con WS-Security: habría que interceptar y reescribir el sobre
igual. Se arma a mano sobre HTTP, que además deja ver exactamente qué se
envía.

## A qué URL se le habla

**No hay que configurarla.** Son dos —habilitación y producción—, son
públicas, iguales para todos los contribuyentes, y vienen puestas de
fábrica en `config/dian.php`.

Cuál se usa lo decide el **ambiente del documento**, que quedó congelado
al emitirlo. Eso es lo que hace que esto funcione en multiempresa: la
empresa A puede estar pasando su set de pruebas mientras la B ya factura
de verdad. Con una sola URL global, encender la producción de una habría
mandado a producción los documentos de prueba de la otra.

El **set de pruebas** va siempre a habilitación, aunque la empresa ya
esté en producción: es el trámite de habilitación.

### El `?wsdl` no es el endpoint

La dirección que publica la DIAN suele terminar en `?wsdl`. Esa devuelve
la *definición* del servicio, para que una herramienta la lea; los
documentos se mandan a la URL **sin** ese sufijo. Se limpia sola, pero
conviene saberlo: mandarlo ahí no falla de forma evidente —contesta con
el WSDL— y el error resultante no dice nada de esto.

```dotenv
DIAN_ENDPOINT=          # solo para apuntar a un intermediario
DIAN_TIMEOUT=60
DIAN_TRANSPORT=auto     # 'fake' fuerza el simulado
```

`DIAN_TRANSPORT=fake` es para un entorno de pruebas que apunta a una
copia de la base de producción: evita que documentos de prueba salgan de
verdad.

## El simulado no miente

`FakeDianTransport` devuelve **error**, no un «aceptado» de mentira. Un
aceptado falso dejaría documentos marcados como validados por la DIAN
que la DIAN no ha visto nunca — exactamente la clase de mentira que no
puede vivir en una tabla fiscal.

## Dónde está cada cosa

| Pieza | Archivo |
|---|---|
| La interfaz | `app/Billing/Dian/Transport/DianTransport.php` |
| La respuesta | `.../TransmissionResult.php` |
| Reintentos e idempotencia | `.../DocumentTransmitter.php` |
| Simulado | `.../FakeDianTransport.php` |
| SOAP real | `.../SoapDianTransport.php` |
| Firma del sobre | `.../XmlSecuritySigner.php` |
| El job | `app/Jobs/TransmitElectronicDocument.php` |
| Barrido | `app/Console/Commands/TransmitPendingDocuments.php` |

Pruebas: `tests/Feature/Billing/DianTransmissionTest.php` (16, la
maquinaria) y `SoapEnvelopeTest.php` (9, el sobre y la respuesta).

Lo que falta está en [Pendientes.md](Pendientes.md).
