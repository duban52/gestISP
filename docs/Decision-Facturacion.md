# Por qué camino sale cada factura

Fase 9 de multiempresa. Deja montados los dos caminos —**factura electrónica** y
**documento interno**—, con la decisión en un solo sitio y las series separadas.

**Todavía no hay ninguna conexión con la DIAN.** Esta fase no transmite nada: lo
que consigue es que los dos caminos existan, se decidan de una sola forma y no
se pisen. La emisión electrónica de verdad llega en las fases 10 y 11.

## La decisión vive en un solo sitio

`ElectronicInvoicingDecider`. Ningún otro punto del sistema toma esta decisión;
si hace falta saberla, se le pregunta.

No es ceremonia por una condición: en cuanto esa condición se escriba en dos
sitios, un día dirán cosas distintas — y el día que lo hagan, la consecuencia es
que un documento sale por el camino equivocado. Eso no se descubre en pruebas:
se descubre cuando alguien revisa.

### La regla

Hacen falta las dos cosas a la vez:

1. **La empresa** tiene la facturación electrónica encendida
   (`electronic_invoicing_enabled`). Es el interruptor general, y **nace
   apagado**: es lo que impide emitir electrónicamente por accidente antes de
   estar habilitado.
2. **El grupo de afinidad** del contrato la exige.

Falta una tercera que hoy no se puede comprobar —que la configuración DIAN de la
empresa esté en producción—. Esa tabla llega en la fase 10, y **su sitio está
marcado dentro del decisor**: se añade ahí y en ningún otro lado.

## Se congela al emitir

`invoices.affinity_group_id` y `invoices.document_kind` se escriben **una vez**,
al emitir la factura, y no se vuelven a calcular.

**Por qué.** Recalcular al vuelo significaría que la misma factura puede
responder cosas distintas según cuándo se pregunte — basta con que alguien
cambie el grupo del contrato. En un documento ya emitido eso es inaceptable.

Es también la decisión D7 del plan: **cambiar el grupo de un contrato nunca
afecta a las facturas ya emitidas**.

Para saber qué es una factura ya emitida se mira su columna
(`facturaEsElectronica`), no se vuelve a decidir.

## Las dos series no comparten consecutivos

Es la decisión D5. `invoice_numbering_sequences` recibe `kind`, y la serie de
una factura sale de su `document_kind`.

Un rango autorizado que se gasta con documentos que nunca se reportan deja
huecos que hay que justificar. Con series separadas eso no puede pasar: emitir
cinco documentos internos no mueve el contador de la serie electrónica.

Los prefijos también se distinguen: `DOC` para el interno y `FE` para el
electrónico. Un documento que no es una factura electrónica no debe parecerlo, y
eso empieza por su número.

Todas las secuencias que ya existían son internas —no hay ninguna resolución
registrada—, así que arrancan como tales y **nada cambia de comportamiento**.

## Cuando no sale electrónica, dice por qué

`motivoInterno()` devuelve la razón concreta: la empresa no está habilitada, el
contrato no tiene grupo, o el grupo emite documento interno.

Existe porque «no salió electrónica» sin decir por qué obliga a revisar tres
sitios distintos.

## Qué queda para las fases siguientes

- **Fase 10** — el documento electrónico: XML, CUFE, firma, resoluciones y
  rangos. Ahí la serie electrónica se ata a una resolución autorizada, y la
  tercera condición del decisor pasa a ser real.
- **Fase 11** — el transporte: el listener de `InvoiceIssued` consultará al
  decisor y encolará el envío. Hoy no encola nada porque no hay a dónde enviar.
