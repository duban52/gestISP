# Pendientes

Todo lo que quedó señalado y sin hacer, con el porqué y dónde se toca.
No es una lista de deseos: cada punto es algo que ya se encontró
construyendo, no algo que se nos ocurrió que estaría bien.

Ordenado por lo que **bloquea** primero.

### 1.1 · Confirmar la política de firma 🔴

`XadesSigner` declara el identificador de la política y su resumen con
los valores de los XML de ejemplo de la DIAN
(`.../politicadefirma/v1/politicadefirmav2.pdf`). Pero **el texto del
anexo 1.9 menciona una ruta `v2` distinta**.

Si el identificador o el resumen no coinciden con la política publicada,
**la DIAN rechaza la firma**.

**Qué hacer:** descargar la política vigente, calcular su SHA-384 y
comparar con las constantes `POLITICA_URL` y `POLITICA_RESUMEN` de
[XadesSigner](../app/Billing/Dian/XadesSigner.php).

### 1.2 · Conseguir el certificado en archivo `.p12` 🔴

Es **el único bloqueante que no depende de programar**. Sin clave privada
no hay firma, y sin firma no hay factura electrónica.

El archivo `.crt` que entregan las entidades de certificación cuando el
certificado se contrata "en la nube" **no sirve**: es solo la mitad
pública. La clave privada se queda en el HSM del proveedor, que es quien
firma por el facturador gratuito de la DIAN. Un servidor propio necesita
el `.p12`.

**Qué hacer:** pedirle a la entidad acreditada el certificado **en
archivo `.p12`/`.pfx`, con la clave privada exportable** — ni en la nube
ni en token de hardware. Ver
[Certificado-Digital.md](Certificado-Digital.md) para el texto exacto de
la solicitud.

**Mientras tanto** hay `php artisan dian:certificado-de-pruebas`, que
genera uno autofirmado y deja recorrer todo el flujo menos la aceptación
de la DIAN.

### 1.3 · La firma, contra un certificado real ⚠️

`XadesSigner` está probado con un certificado autofirmado: la firma
verifica, los resúmenes se recalculan y el documento sigue validando
contra el XSD. Lo que **no** se ha comprobado es que la DIAN la acepte,
porque rechazaría el autofirmado por política, no por mecánica.

**Qué hacer:** cargar el `.p12` real y emitir un documento contra el
ambiente de habilitación.

### 2.1 · Contingencia tipo 04

El anexo (§12.2) manda que, agotados los reintentos, se expida el
documento **sin validación previa**, con `InvoiceTypeCode = 04`, y se
transmita dentro de las 48 horas siguientes.

Hoy la situación **se detecta** —`DocumentTransmitter::agotoLosIntentos()`
y un aviso en el log— pero no se actúa: no se reemite con el código 04
ni se lleva el plazo de 48 horas.

---

### 2.2 · Consultar el estado de un documento ya enviado

`SendBillSync` valida en el momento, pero la DIAN también expone
consulta por `trackId` (que ya se guarda en
`electronic_documents.dian_track_id`). Hoy no se consulta nunca: si una
respuesta se pierde, el documento se queda «firmado» para siempre.

---

### 2.3 · Los valores exactos de WS-Security

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

### 3.2 · Producir el set de pruebas que exige la DIAN

La fase 12 dejó hecho el envío (`dian:set-de-pruebas`), pero **qué
documentos van dentro sigue siendo manual**: la DIAN asigna un set con
una mezcla concreta —con descuento, con varios impuestos, exento…— y hay
que producirla emitiendo en el ambiente de pruebas.

No se automatizó a propósito: generar facturas sintéticas dentro de una
base de producción es exactamente la clase de cosa que no debe hacer un
comando por su cuenta.

### 3.3 · Consultar el resultado del set de pruebas

`SendTestSetAsync` devuelve una `ZipKey` de acuse y valida después. Hoy
la guardamos en la trazabilidad pero no consultamos el resultado: hay
que entrar al portal de la DIAN a mirarlo.

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
| Diagnóstico de preparación e interruptor guardado | ✅ |
| Alertas de certificado y rango (en el planificador) | ✅ |
| Envío del set de pruebas | ✅ |
| Pantallas de administración DIAN | ✅ |
| Notas electrónicas | ✅ |
| IVA gravado / excluido / exento | ✅ |

**En una frase:** el sistema produce facturas y notas electrónicas
correctas, se configura sin un programador, sabe a qué servicio de la
DIAN hablarle según el ambiente de cada empresa, y sabe qué hacer con
las respuestas. Lo que falta es **probarlo contra la DIAN de verdad**:
cargar el certificado, confirmar la política de firma y correr el set de
pruebas.
