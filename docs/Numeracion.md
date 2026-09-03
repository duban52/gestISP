# Numeración de documentos

Fase 6 de multiempresa. Unifica en un solo servicio la lógica que estaba
escrita tres veces.

## De dónde viene

Había tres mecanismos, cada uno con su copia de lo mismo:

| Documento | Dónde vivía el contador |
|---|---|
| Contrato | dos columnas en la propia fila de `branches` |
| Factura | tabla `invoice_numbering_sequences` |
| Nota C/D | tabla `note_numbering_sequences` |

Los tres funcionaban. El problema no era que estuvieran rotos: era que había
**tres sitios donde arreglar el mismo fallo** y tres suites probando la misma
garantía.

## La garantía

Es una sola y es la razón de que el servicio exista:

> **Dos altas simultáneas nunca reciben el mismo número.**

Se consigue con bloqueo pesimista sobre la fila de la serie (`lockForUpdate`).
El segundo proceso espera al commit del primero y lee un contador ya
incrementado. No es optimista ni depende de reintentos.

Por eso **todo esto corre dentro de una transacción**: el bloqueo vive hasta el
commit. Si quien llama no abrió una, la abre el servicio — pero lo normal es
que ya la tenga, porque el número se reserva junto con el documento que lo
lleva, y si el documento falla el número tiene que volver atrás con él.

## Qué se movió y qué no

**`document_sequences`** es la numeración **interna**: la que se inventa la
empresa. Ahí se mudaron contratos y notas.

**Las facturas no mudaron su contador**, y es deliberado. Su tabla nació con
resolución, vigencia y rango porque se diseñó anticipando la DIAN, y cuando
llegue la numeración fiscal esos consecutivos irán a `numbering_ranges`.
Moverlos ahora a la interna sería moverlos dos veces, y un consecutivo de
factura movido de más es exactamente lo que no conviene repetir.

Lo que sí se comparte es el **algoritmo**. `DocumentNumberService::reservarEn()`
—sumar uno, comprobar el rango, formatear— trabaja sobre cualquier
`SerieNumerable`, y las dos tablas de series la implementan.

Que el rango sea común es lo que más importa: **emitir pasado el rango
autorizado es emitir con números que nadie autorizó**, y esa comprobación no
puede tener dos versiones que se separen con el tiempo.

## La semilla, que es lo que evita repetir

Cuando la serie no existe, **no se crea en cero**: se crea desde el mayor
consecutivo REALMENTE usado, que quien llama sabe calcular y pasa en `$semilla`.

Sin eso, una serie recién creada entregaría el número 1 a un sistema que ya
tiene mil contratos, y el UNIQUE de la base rechazaría el alta después de que
alguien haya rellenado el formulario entero.

Es también lo que hace que el comando de migración **no sea obligatorio**: si no
se ejecuta, la serie nace bien igualmente la primera vez que se pide un número.

## Una sola serie activa por combinación

Con dos activas para el mismo tipo y la misma sucursal, de cuál sale el número
siguiente dependería del orden de la consulta, y las dos avanzarían en paralelo
entregando los mismos consecutivos.

Se garantiza en la **base**, con la misma técnica que el grupo de afinidad
predeterminado: una columna generada que vale la combinación cuando la serie
está activa y `NULL` cuando no, con un `UNIQUE` encima.

```sql
active_key = IF(active = 1, CONCAT(company_id,'-',COALESCE(branch_id,0),'-',document_type), NULL)
```

El `COALESCE` importa: `branch_id` admite nulo —una serie puede ser de toda la
empresa— y `NULL` nunca es igual a `NULL` en un índice. Sin él, dos series de
empresa del mismo tipo pasarían las dos.

Las **inactivas** conviven sin límite a propósito: son el histórico de qué
prefijo se usó y hasta dónde llegó.

## El formato es un dato de la serie

`padding` vive en la fila, no como constante en cada servicio. Los contratos van
a seis dígitos (`ENG000001`) y las notas sin rellenar (`NC-1`). En las notas, el
separador pasó a vivir **dentro del prefijo** (`NC-`), de modo que el formato
entero sale de la serie y no de código repartido.

## El comando de migración

```bash
php artisan numeracion:migrar --dry-run    # primero esto, siempre
php artisan numeracion:migrar              # y solo después esto
```

**Por qué un comando y no la migración.** Un contador mal copiado repite números
de documentos ya emitidos. Eso tiene que poder revisarse antes de escribir nada,
y una migración no da esa oportunidad: corre sola en el despliegue.

El simulacro imprime, por sucursal y tipo: el contador viejo, el mayor
consecutivo realmente emitido, y en cuál queda. **El contador sale del mayor de
los dos**, nunca del contador a secas — el prefijo se puede cambiar y hay
contratos importados con su propio número.

Después de escribir, verifica que ningún documento emitido supere su contador y
**falla con código de error** si alguno lo hace. Si eso ocurre, no hay que
emitir nada hasta revisarlo.

El comando es **idempotente** y el contador **solo sube**: un segundo pase no
puede bajar una serie que ya entregó números.

## El prefijo se sigue editando en la sucursal

Cambiar `contract_prefix` desde la ficha de la sucursal sigue funcionando:
`Branch` sincroniza el cambio con la serie en un hook `saved`.

Sin eso, el cambio se guardaría en la columna y los contratos nuevos seguirían
saliendo con el prefijo viejo — porque es la serie quien lo decide. Un cambio
que no hace nada y no avisa.

El campo se conserva ahí y no se movió a otra pantalla porque es donde la gente
ya sabe buscarlo, y porque durante la transición sirve de red de seguridad — el
mismo criterio que con `branches.nit`.

## La API pública no cambió

`ContractNumberGenerator` conserva `siguiente()`, `asignar()`,
`registrarNumeroExterno()` y `formatear()`, con la misma firma y el mismo
comportamiento. Lo que se cambió es el motor de debajo.

Es la señal de que la fase salió bien: las veinte pruebas de numeración e
importación que ya existían pasaron sin tocar ninguna.

## Qué queda para las fases siguientes

- **Fase 9** — `FiscalNumberResolver` decidirá de qué serie sale el número de
  cada factura: la interna si su grupo de afinidad es interno, la fiscal si es
  electrónico. Las dos series no comparten consecutivos.
- **Fase 10** — `dian_resolutions` y `numbering_ranges`. Los consecutivos de
  factura se mudan ahí, y `InvoiceNumberingSequence` deja de usarse. El
  algoritmo no cambia: la tabla nueva implementará `SerieNumerable` igual que
  las dos actuales.
