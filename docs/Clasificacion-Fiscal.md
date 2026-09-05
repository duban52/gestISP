# Clasificación fiscal: gravado, excluido y exento

Por qué el IVA de un servicio no se puede deducir de su tarifa, y cómo
queda resuelto.

---

## El problema

La distinción vivía **implícita** en `tax_percentage = 0`. Y ese cero
significaba tres cosas que el sistema no podía diferenciar:

- **excluido** — la ley no sujeta ese servicio a IVA;
- **exento** — sujeto, pero a tarifa 0%;
- o que a alguien se le olvidó poner la tarifa.

## Y no son tres matices de lo mismo

| | ¿Causa IVA? | ¿Se descuenta el IVA de las compras? |
|---|---|---|
| **Gravado** | Sí, a su tarifa | Sí |
| **Excluido** | **No** | **No** — se vuelve costo |
| **Exento** | Sí, de 0 | **Sí**, incluso con derecho a devolución |

La diferencia entre excluido y exento no es cosmética: es si la empresa
recupera o no el IVA que pagó. Para un ISP eso es dinero.

## En el XML son estructuras distintas

Verificado en los ejemplos oficiales de la DIAN (`Excluido de IVA.xml` y
`Exento de IVA.xml`, los dos validan contra el XSD):

**Excluido** — el documento **no lleva `cac:TaxTotal`**. Ni en la línea,
ni en el total. Simplemente no existe el bloque de impuestos.

**Exento** — sí lo lleva, con ceros explícitos:

```xml
<cac:TaxTotal>
  <cbc:TaxAmount currencyID="COP">0.00</cbc:TaxAmount>
  <cac:TaxSubtotal>
    <cbc:TaxableAmount currencyID="COP">50000.00</cbc:TaxableAmount>
    <cbc:TaxAmount currencyID="COP">0.00</cbc:TaxAmount>
    <cac:TaxCategory>
      <cbc:Percent>0.00</cbc:Percent>
      <cac:TaxScheme><cbc:ID>01</cbc:ID><cbc:Name>IVA</cbc:Name></cac:TaxScheme>
    </cac:TaxCategory>
  </cac:TaxSubtotal>
</cac:TaxTotal>
```

**Decir «cero impuesto» y «no hay impuesto» son cosas diferentes para la
DIAN.**

## Cómo queda

`services.tax_classification` — un dato explícito, no un porcentaje en
cero. Lo pone quien lleva la contabilidad, en el formulario del
servicio.

El XML decide por **la clasificación, no por la tarifa**: un excluido
con la tarifa mal puesta sigue saliendo sin bloque de impuestos.

Y se **congela en la línea** de la factura, igual que el código de
producto: una factura emitida tiene que seguir diciendo cómo se trató el
IVA aunque mañana se reclasifique el servicio — su XML ya se transmitió.

## El aviso por estrato

El internet residencial de **estratos 1, 2 y 3 está excluido de IVA**;
para los demás se grava a la tarifa general.

Esa regla **avisa, no decide**, y es deliberado: meterla dentro del
código que factura sería enterrar derecho tributario donde nadie lo ve,
y que un cambio de ley obligue a un despliegue.

Lo que hace es señalar la combinación sospechosa —un estrato 5
facturando internet excluido, o un estrato 2 pagando IVA— al dar de alta
o editar un contrato. **No bloquea**: puede haber razones legítimas,
como un contrato empresarial en una dirección de estrato bajo.

## Lo que la migración adivinó, y hay que revisar

Al migrar lo que ya existía no había de dónde sacar la clasificación más
que de la tarifa:

- tarifa > 0 → **gravado**
- tarifa = 0 → **excluido**

Que es exactamente lo que este campo viene a dejar de hacer. **Hay que
repasar el resultado en la pantalla de servicios**, porque en los datos
actuales hay dos parejas que se contradicen:

| Servicio | Tarifa | Quedó como | ¿Correcto? |
|---|---|---|---|
| Internet 30 / 50 / 80 / 100 mbps | 0% | excluido | Sí, si es residencial estrato 1-3 |
| **INTERNET 200MB** | **19%** | gravado | ¿Es el mismo servicio que los otros? |
| Servicio de TV | 19% | gravado | Sí, la TV por suscripción se grava |
| **Television** | **0%** | **excluido** | **Probablemente mal** — revisar |

## Dónde está cada cosa

| Pieza | Archivo |
|---|---|
| Los tres estados | `app/Billing/Enums/TaxClassification.php` |
| El aviso por estrato | `app/Billing/Services/TaxClassificationAdvisor.php` |
| Cómo lo usa el XML | `InvoiceXmlBuilder::impuestos()` y `::clasificacionDe()` |
| Dónde se congela | `InvoiceGenerator`, en `invoice_items.tax_classification` |

Pruebas: `tests/Feature/Billing/TaxClassificationTest.php` (11).
