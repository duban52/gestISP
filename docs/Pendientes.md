# Pendientes

Todo lo que quedó señalado y sin hacer, con el porqué y dónde se toca.
No es una lista de deseos: cada punto es algo que ya se encontró
construyendo, no algo que se nos ocurrió que estaría bien.

Ordenado por lo que **bloquea** primero.

---

## 1. Bloquean facturar electrónicamente

### 1.1 · No hay pantallas de administración DIAN 🔴

Hoy la configuración DIAN, el certificado, las resoluciones y los rangos
de numeración **solo se pueden crear por consola** (`tinker` o un
seeder). No hay ninguna interfaz.

Sin esto nadie puede poner el sistema a facturar electrónicamente sin un
programador delante.

**Qué hace falta:** CRUD de `DianConfiguration` (ambiente, SoftwareID,
PIN), `DianCertificate` (con subida del `.p12` **fuera del directorio
público** y contraseña cifrada), `DianResolution` y sus
`NumberingRange`.

**Cuidado con:** el certificado es una clave privada. Va como ARCHIVO
con su ruta en la base, nunca como columna — en una columna acabaría
también en cada copia de seguridad y en cada volcado. Y hay que
verificar que **no viaje** en el backup general (ver §4.3).

---

### 1.2 · La URL del servicio de la DIAN 🔴

No la publica la DIAN en su documentación: la expone dentro de la cuenta
del catálogo de cada facturador (**Participants → Facturador**), y es
distinta en habilitación y en producción.

Sin ella `SoapDianTransport` no tiene a dónde enviar y el sistema usa el
transporte simulado.

**Qué hacer:** entrar al catálogo de la DIAN, copiar la URL y ponerla en
`DIAN_ENDPOINT`.

---

### 1.3 · Confirmar la política de firma 🔴

`XadesSigner` declara el identificador de la política y su resumen con
los valores de los XML de ejemplo de la DIAN
(`.../politicadefirma/v1/politicadefirmav2.pdf`). Pero **el texto del
anexo 1.9 menciona una ruta `v2` distinta**.

Si el identificador o el resumen no coinciden con la política publicada,
**la DIAN rechaza la firma**.

**Qué hacer:** descargar la política vigente, calcular su SHA-384 y
comparar con las constantes `POLITICA_URL` y `POLITICA_RESUMEN` de
[XadesSigner](../app/Billing/Dian/XadesSigner.php).

---

### 1.4 · IVA exento y excluido 🔴

Un ISP colombiano factura internet residencial de **estratos 1, 2 y 3
sin IVA**. En el XML eso NO es «porcentaje cero»: son estructuras
propias, con su código de motivo de exención.

Hoy las líneas sin impuesto simplemente no suman al `TaxTotal` —
correcto para lo gravado, **incorrecto para lo exento**.

**Dónde:** `InvoiceXmlBuilder::impuestos()` y `::lineas()`. Los ejemplos
`Exento de IVA.xml` y `Excluido de IVA.xml` del paquete oficial traen la
estructura exacta.

**Bloquea:** facturar electrónicamente a los estratos 1-3, que para un
ISP suele ser la mayor parte de la base de clientes.

---

### 1.5 · La firma, contra un certificado real ⚠️

`XadesSigner` está probado con un certificado autofirmado: la firma
verifica, los resúmenes se recalculan y el documento sigue validando
contra el XSD. Lo que **no** se ha comprobado es que la DIAN la acepte,
porque rechazaría el autofirmado por política, no por mecánica.

**Qué hacer:** cargar el `.p12` real y emitir un documento contra el
ambiente de habilitación.

---

## 2. Quedaron a medias, por decisión

### 2.1 · Notas crédito y débito electrónicas

`NoteIssuer` numera **siempre** por la serie interna
(`DocumentSequence`), incluso cuando la nota corrige una factura
electrónica. La decisión **D6** del plan maestro dice que deberían
seguir el mismo camino que la factura que corrigen.

**Dónde:** `App\Billing\Services\NoteIssuer::numeroDeNota()`.

**Además hace falta:** el **CUDE** (el equivalente del CUFE para notas,
§11.4 del anexo) y el XML de `CreditNote`/`DebitNote`, cuyos XSD ya
están en `resources/dian/xsd/maindoc/`.

---

### 2.2 · Contingencia tipo 04

El anexo (§12.2) manda que, agotados los reintentos, se expida el
documento **sin validación previa**, con `InvoiceTypeCode = 04`, y se
transmita dentro de las 48 horas siguientes.

Hoy la situación **se detecta** —`DocumentTransmitter::agotoLosIntentos()`
y un aviso en el log— pero no se actúa: no se reemite con el código 04
ni se lleva el plazo de 48 horas.

---

### 2.3 · Consultar el estado de un documento ya enviado

`SendBillSync` valida en el momento, pero la DIAN también expone
consulta por `trackId` (que ya se guarda en
`electronic_documents.dian_track_id`). Hoy no se consulta nunca: si una
respuesta se pierde, el documento se queda «firmado» para siempre.

---

### 2.4 · Los valores exactos de WS-Security

La guía de consumo de servicios web muestra el tipo de identificador de
clave y los algoritmos **en una imagen**, que no se puede leer del PDF.
`XmlSecuritySigner` usa los estándar de este perfil.

Es lo primero que hay que revisar si la DIAN devuelve un error de
autenticación.

---

## 3. Acordado, sin empezar

### 3.1 · Compartir planes y servicios entre sucursales

Hoy `Plan` y `Service` están atados a **una** `branch_id` y se duplican
por sede: un contrato de una sucursal no puede usar un plan de otra ni
en modo consolidado (`ContractController` exige
`Rule::exists('plans','id')->where('branch_id', $branchId)`).

Es literalmente la decisión **D8** del plan maestro —«Servicio →
empresa, que lleva UNSPSC e IVA; Plan → sucursal con el precio de la
empresa por defecto»— que quedó escrita y nunca se implementó.

**Lo delicado no es el código sino los datos:** hay planes y servicios
YA duplicados por sucursal en la base real. Antes de migrar hay que
decidir qué se hace con ellos —¿se fusionan?, ¿cuál gana si tienen
precios distintos?—.

**Acordado:** planificarlo como fase aparte, con su documento, antes de
tocar nada.

---

### 3.2 · Fase 12 — Habilitación y producción gradual

El proceso ante la DIAN: pasar el set de pruebas, obtener la
habilitación y encender la facturación electrónica poco a poco en vez de
de golpe.

Nota: hoy `ElectronicInvoicingDecider` exige ambiente de **producción**
para emitir electrónicamente, así que el set de pruebas de habilitación
no puede correr por el camino normal. Eso es deliberado —impide emitir
documentos sin validar a clientes reales— pero hay que resolverlo en
esta fase.

---

## 4. Deuda anterior a todo esto

### 4.1 · La tabla `audits` sin política de retención 🔴

Se encontró el disco **al 100%** con `audits` ocupando **2,67 GB**. No
hay purga, ni archivado, ni rotación.

Va a volver a pasar.

### 4.2 · Los informes en modo consolidado

El plan maestro (§K.2) señala que las pantallas paginadas y los informes
cruzan sucursales sin haberlo pensado, y que en consolidado hay que
decidir si un informe **suma** varias sedes o las **separa**. No se
cubrió en ninguna fase.

### 4.3 · Copias de seguridad y multiempresa

Un backup restaurado revuelve contextos si hay varias empresas. Y ahora
que hay certificados, hay que verificar explícitamente que **no viajan**
en el backup general.

---

## Cómo está el sistema hoy

| | Estado |
|---|---|
| Factura electrónica: decisión, numeración autorizada | ✅ |
| XML UBL 2.1 validado contra el XSD de la DIAN | ✅ |
| CUFE, código de software, QR | ✅ |
| Firma XAdES (mecánica verificada) | ✅ |
| Transmisión: reintentos, estados, idempotencia, registro | ✅ |
| Transporte SOAP real | ⚠️ escrito, sin verificar |
| Pantallas de administración DIAN | ❌ |
| Notas electrónicas | ❌ |
| IVA exento/excluido | ❌ |

**En una frase:** el sistema produce documentos electrónicos correctos y
sabe qué hacer con las respuestas; lo que falta es poder configurarlo
sin un programador, y las credenciales para probarlo de verdad.
