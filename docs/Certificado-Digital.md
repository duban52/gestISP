# El certificado digital de firma

Todo documento que gestISP le presenta a la DIAN va **firmado**. La firma
es lo que prueba que lo emitió el contribuyente y que nadie lo tocó
después. Sin certificado no hay firma, y sin firma no hay factura
electrónica: el documento se queda en `GENERADO` y no se transmite.

Este documento explica de dónde sale ese certificado, por qué el archivo
que suelen entregar **no sirve**, y qué hacer mientras llega.

---

## Qué es, en concreto

Dos cosas que van juntas:

| | Qué es | Quién la tiene |
|---|---|---|
| **Clave privada** | Con ella se firma | Solo el contribuyente |
| **Certificado** | Dice quién es el dueño de esa clave, y lo avala una entidad | Público, viaja dentro del documento firmado |

gestISP necesita **las dos**, en un solo archivo `.p12` (o `.pfx`, que es
lo mismo). `XadesSigner` lo abre así:

```php
openssl_pkcs12_read($p12, $contenido, $clave);
// necesita $contenido['pkey'] — la clave privada
```

## Por qué un `.crt` no sirve

Un `.crt` es **solo la mitad pública**. Sirve para que el otro lado
verifique una firma, no para producir una.

Es el archivo que entregan las entidades de certificación cuando se
contrata la modalidad **"en la nube"**: ellas generan la pareja de
claves, se quedan con la privada en su propio HSM, y la instalan
directamente en el facturador gratuito de la DIAN. El facturador
gratuito puede firmar porque *ellas* firman por él. Un servidor propio,
no.

No es un archivo defectuoso ni le falta una conversión: la información
sencillamente no está ahí.

**Cómo distinguirlo de un vistazo:**

```bash
openssl pkcs12 -info -in certificado.p12 -nodes | grep "PRIVATE KEY"
```

Si no aparece nada, o si el archivo es `.crt`/`.cer`/`.pem` sin más, no
sirve para firmar.

## Qué pedirle a la entidad de certificación

El certificado lo vende una entidad acreditada por la **ONAC** (GSE,
Andes SCD, Certicámara y otras). Da igual cuál — lo que importa es cómo
lo entregan. Al contratarlo o al pedir una reemisión, hay que decir
exactamente esto:

> Tengo software propio de facturación electrónica que firma en mi
> servidor. Necesito el certificado **en archivo `.p12` o `.pfx`, con la
> clave privada exportable y su contraseña**. No me sirve en la nube ni
> en token de hardware.

Las tres formas en que suele torcerse:

- **En la nube.** La clave se queda en el HSM del proveedor. Solo
  entregan el `.crt`. Es lo que produce este problema.
- **En token USB.** El certificado vive en un dispositivo físico que hay
  que tener conectado y desbloqueado. La corrida mensual de facturación
  firma de madrugada, sin nadie delante: no vale.
- **En archivo.** Es el que sirve.

Si la entidad entrega el `.crt` y la `.key` por separado, se arma el
`.p12` en un comando:

```bash
openssl pkcs12 -export -inkey privada.key -in certificado.crt -certfile cadena.crt -out certificado.p12
```

La cadena (`-certfile`) importa: la DIAN espera un `xades:Cert` por cada
certificado de la cadena, no solo el del firmante.

## Cómo se carga en gestISP

Panel DIAN → **Certificado digital** → elegir el `.p12` y su contraseña.

Al cargarlo se comprueba que **abra**, porque descubrir que la
contraseña estaba mal el día de firmar es descubrirlo a mitad de la
corrida mensual, con consecutivos autorizados ya gastados. Las fechas de
vigencia se leen del propio certificado, no se teclean.

El archivo se guarda fuera del directorio público y **no hay ninguna
ruta para descargarlo**. La contraseña se guarda cifrada y no se
devuelve nunca al formulario. Cargar o retirar un certificado queda en
la trazabilidad: cambia quién puede firmar en nombre de la empresa.

---

## Mientras llega: el certificado de pruebas

Conseguir el certificado real depende de un tercero y puede tardar
semanas. Para no quedarse parado:

```bash
php artisan dian:certificado-de-pruebas --empresa=7
```

Genera un certificado **autofirmado** a nombre de la empresa y lo deja
activo. Con él se recorre el camino entero pasando por exactamente el
mismo código que pasará el día que haya uno real.

| | Con el de pruebas |
|---|---|
| Armar el XML UBL | ✅ |
| Validar contra el XSD de la DIAN | ✅ |
| CUFE / CUDE, código de seguridad, QR | ✅ |
| Firma XAdES completa y verificable | ✅ |
| Documento en estado `FIRMADO` en pantalla | ✅ |
| Armar el sobre SOAP y transmitir | ✅ |
| **Que la DIAN lo acepte** | ❌ |

La firma que produce es **correcta**: verifica con su clave pública y
los resúmenes cuadran. Lo que falla es quién la avala — la DIAN exige
que el certificado encadene contra una entidad acreditada, y este se
firma a sí mismo.

De paso, sirve para responder de primera mano una pregunta que si no hay
que ir a buscar: mandar el set de pruebas firmado así y ver qué contesta
la DIAN cuesta un minuto.

### Los tres candados

Un certificado de pruebas olvidado en una empresa que ya factura
significa firmar un mes entero con algo que la DIAN rechaza, y
descubrirlo cuando ya se gastaron los consecutivos. Por eso:

1. **El comando se niega** si la empresa está en ambiente de producción.
2. Queda marcado en la base (`dian_certificates.self_signed`) y en su
   nombre.
3. El panel lo señala con una etiqueta roja **Pruebas**, y
   `dian:diagnostico` lo declara **bloqueante** — aunque esté ahí,
   activo y vigente. La pregunta que contesta el diagnóstico no es «¿se
   puede firmar?» sino «¿se puede emitir?».

El marcado **no depende del comando**: un autofirmado subido a mano por
la pantalla queda marcado igual. Se comprueba comparando el emisor con
el titular, que es lo que quien sube el archivo puede no saber mirar —
desde fuera un autofirmado y uno real se ven idénticos.

Al cargar el certificado de verdad, el de pruebas se desactiva solo.

---

## Dónde está cada cosa

| Pieza | Archivo |
|---|---|
| Firma XAdES | `app/Billing/Dian/XadesSigner.php` |
| Generador autofirmado | `app/Billing/Dian/SelfSignedCertificate.php` |
| Comando | `app/Console/Commands/GenerateTestCertificate.php` |
| Carga por pantalla | `app/Http/Controllers/DianCertificateController.php` |
| Modelo y vigencia | `app/Models/DianCertificate.php` |
| Diagnóstico | `app/Billing/Dian/DianReadiness.php` |
| Pruebas | `tests/Feature/Billing/TestCertificateTest.php` |

Ver también [Habilitacion-DIAN.md](Habilitacion-DIAN.md) para el camino
completo de cero a producción, y [Pendientes.md](Pendientes.md) para lo
que sigue abierto.
