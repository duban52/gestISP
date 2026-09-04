# Notas crédito y débito electrónicas

Una nota que corrige una factura electrónica tiene que ser también
electrónica: si no, se estaría ajustando ante la DIAN un documento que
ella validó, sin decírselo.

---

## Lo que la investigación cambió del plan

La decisión **D6** del plan maestro daba por hecho que una nota
electrónica tendría que **numerarse de un rango autorizado**, igual que
la factura.

**Es falso.** Se comprobó de dos formas independientes:

1. El XML de una nota **no lleva `sts:InvoiceControl`** — el bloque de
   la resolución y el rango. Se verificó en los ejemplos oficiales
   `CreditNote.xml` y `DebitNote.xml` del paquete de la DIAN.
2. El propio anexo lo dice (§12.1): para estos documentos el facturador
   «no deberá usar la numeración de contingencia […] sino **la
   numeración establecida por el facturador**».

Así que la numeración propia que ya hacía `NoteIssuer` —`NC-1`, `ND-1`—
**era la correcta desde el principio**. Lo que faltaba era otra cosa: el
CUDE, el XML y la transmisión.

Vale la pena dejarlo escrito porque el plan decía lo contrario, y
alguien podría "corregirlo" más adelante.

## El CUDE es el CUFE con un cambio, y ese cambio lo es todo

La cadena tiene exactamente la misma forma —los mismos catorce campos,
en el mismo orden, con el mismo formato— salvo en la penúltima
posición:

| | Lleva |
|---|---|
| **CUFE** | la **clave técnica** de la resolución |
| **CUDE** | el **PIN del software** |

Tiene sentido: una nota no sale de un rango autorizado, así que no hay
clave técnica que usar. Lo que la respalda es el software que la
produjo.

Confundirlas produce un hash perfectamente válido que la DIAN rechaza, y
mirando el resultado no hay forma de saber cuál de los dos secretos se
usó.

**Verificado** contra el ejemplo resuelto del anexo (§11.4.3), igual que
el CUFE.

## Crédito y débito no son el mismo documento

| | Nota crédito | Nota débito |
|---|---|---|
| Raíz | `CreditNote` | `DebitNote` |
| Totales | `cac:LegalMonetaryTotal` | **`cac:RequestedMonetaryTotal`** |
| Líneas | `cac:CreditNoteLine` / `cbc:CreditedQuantity` | `cac:DebitNoteLine` / `cbc:DebitedQuantity` |
| Código de tipo | `cbc:CreditNoteTypeCode` = 91 | *(UBL no tiene ese elemento)* |

Usar el elemento de totales que no toca invalida el documento **contra
su propio esquema**. Por eso hay una prueba por cada tipo, cada una
contra su XSD.

## La nota apunta a su factura, dos veces

```xml
<cac:DiscrepancyResponse>          <!-- el motivo -->
  <cbc:ReferenceID>SETP990000000</cbc:ReferenceID>
  <cbc:ResponseCode>2</cbc:ResponseCode>      <!-- concepto oficial DIAN -->
  <cbc:Description>Anulación de factura</cbc:Description>
</cac:DiscrepancyResponse>

<cac:BillingReference>             <!-- la referencia formal -->
  <cac:InvoiceDocumentReference>
    <cbc:ID>SETP990000000</cbc:ID>
    <cbc:UUID schemeName="CUFE-SHA384">…el CUFE de la factura…</cbc:UUID>
    <cbc:IssueDate>2026-09-05</cbc:IssueDate>
  </cac:InvoiceDocumentReference>
</cac:BillingReference>
```

El **CUFE de la factura corregida** es lo que ata la nota a su
documento. Sin él la nota queda huérfana y la DIAN la rechaza — por eso,
si la factura no tiene documento electrónico, la nota no se puede armar
y se anota el motivo.

El `ResponseCode` es el código de concepto oficial, el mismo que
`NoteType` ya guardaba desde que se construyeron las notas internas.

## Qué decide si una nota es electrónica

**Su factura.** No se vuelve a decidir nada: se lee `document_kind` de
la factura, cuyo tipo ya quedó congelado al emitirla, y se congela en la
nota.

Apagar después la facturación electrónica de la empresa no cambia lo ya
emitido.

## Un fallo no tumba la emisión

Misma regla que en las facturas, y aquí importa igual: la nota **ya está
emitida y ya ajustó el saldo** de la factura. Que no se pueda armar su
XML no puede deshacer eso.

El fallo se atrapa y se anota en `last_error` del documento, que queda
en borrador. Reintentar completa esa misma fila.

## De paso: un refactor

`InvoiceXmlBuilder` y `NoteXmlBuilder` comparten las partes —emisor,
adquiriente, direcciones con código DANE, responsabilidades, formato de
importes—, que es justo donde están los errores que cuestan días. Se
extrajeron a `UblBuilder`.

Lo que cada documento tiene de suyo —qué elementos lleva y en qué
orden— vive en su propia clase, porque en UBL el orden es parte del
contrato y no se puede generalizar.

## Dos bugs que encontraron las pruebas

1. **Código muerto tras un `return`.** El enganche del generador quedó
   después de `return DB::transaction(...)`, así que no se ejecutaba
   nunca. Se ve en que las pruebas de decisión pasaban y las de
   documento no.
2. **Columna no `fillable`.** `credit_debit_note_id` se añadió a la
   tabla pero no al modelo, así que la asignación masiva la descartaba
   **en silencio**: el documento se creaba sin apuntar a su nota.

## Dónde está cada cosa

| Pieza | Archivo |
|---|---|
| CUDE | `app/Billing/Dian/CudeCalculator.php` |
| XML de la nota | `app/Billing/Dian/NoteXmlBuilder.php` |
| Lo común con la factura | `app/Billing/Dian/UblBuilder.php` |
| Orquestación | `app/Billing/Dian/NoteDocumentGenerator.php` |
| La decisión y el enganche | `app/Billing/Services/NoteIssuer.php` |

Pruebas: `tests/Unit/Dian/CudeCalculatorTest.php` (5, contra el ejemplo
de la DIAN) y `tests/Feature/Billing/ElectronicNoteTest.php` (12).
