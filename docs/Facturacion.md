# Manual de facturación de gestISP

Cómo se decide **qué se cobra**, **a quién**, **de qué período**, **qué
le llega al cliente** y **cuándo se le corta el servicio**.

**Para quién es esto.** Para quien configura una sucursal, lanza la
facturación del mes o tiene que explicarle a un cliente por qué su
factura dice lo que dice. No hace falta saber programar. La última
sección sí es para quien toca el código.

**Cómo leerlo.** Las secciones 1 y 2 explican el vocabulario; sin eso,
el resto se lee como una lista de botones. La 3 es la configuración,
que se hace una vez. La 4 y la 5 son el trabajo de todos los meses. La
6 son casos resueltos con las cuentas hechas. La 7 es para cuando algo
no salió como se esperaba.

---

# PARTE I — LOS CONCEPTOS

## 1. Quién le factura a quién

### 1.1 Empresa y sucursal

La **empresa** es el contribuyente: tiene NIT, certificado digital,
resolución de la DIAN y registro TIC. Es quien firma las facturas.

La **sucursal** es la sede que opera: tiene sus clientes, su caja, su
inventario y **sus propias reglas de facturación**. Una empresa puede
tener varias.

> **Esto importa más de lo que parece:** la configuración de
> facturación es **de la sucursal**, no de la empresa. Dos sedes de la
> misma empresa pueden cobrar con reglas distintas. Desde la ficha de
> la empresa se pueden **copiar a todas** de una vez, pero la que se
> aplica siempre es la de cada sucursal.

### 1.2 Cliente y contrato

El **cliente** es la persona o la empresa. El **contrato** es lo que se
le factura: un plan, una dirección, una fecha de activación. Un cliente
puede tener varios contratos —dos casas, un local— y **cada contrato se
factura por separado**.

La factura se le emite al **titular del contrato en el momento de
emitirla**, y ese titular queda congelado en el documento. Si mañana el
contrato se cede a otra persona, las facturas viejas siguen diciendo el
nombre de antes, que es lo correcto: son de quien las debía.

### 1.3 Estados del contrato

El estado decide si el contrato entra en la facturación del mes.

| Estado | ¿Se le factura? | Qué significa |
|---|---|---|
| **Por Instalar** | No | Firmó, pero todavía no tiene servicio |
| **Activo** | **Sí** | Con servicio, al día |
| **Pre-suspensión** | **Sí** | Tiene vencidas y ya se le anunció el corte, pero **aún tiene servicio** |
| **Suspendido** | No | Servicio cortado por mora |
| **Por Reconexión** | No | Pagó estando cortado; espera la visita que le devuelve el servicio |
| **Retirado** | No | Tuvo servicio y lo dejó |
| **Anulado** | No | Firmó y nunca tomó el servicio |
| **Cedido** | No | Heredado de las cesiones antiguas; ya no se usa |

**Retirado y Anulado no son lo mismo**, y por eso son dos estados:
contarlos juntos mentiría en los informes de bajas. Una anulación no es
un cliente perdido; es un contrato que nunca empezó.

**Un suspendido no se factura.** Mientras esté cortado no se le suman
mensualidades: lo que debe es lo que ya se le facturó.

### 1.4 Plan y servicios

El **plan** es lo que el cliente contrató («Internet 100 megas + TV»).
Por dentro son uno o varios **servicios**, y cada servicio tiene:

- **Precio base** — sin IVA.
- **Porcentaje de IVA** — 0 si el servicio es excluido, que es lo
  habitual en el internet residencial.
- **Código de producto (UNSPSC)** y **unidad de medida** — los exige la
  DIAN en el XML.
- **Clasificación fiscal** — cómo se trata el IVA.

Cada servicio del plan sale como **un renglón** en la factura. Un plan
de internet + TV produce dos renglones, no uno.

> **Si el contrato se queda sin plan, no se factura.** Pasa si alguien
> borra un plan que estaba en uso. La corrida lo omite con «nada que
> facturar» en lugar de emitir una factura vacía — en una electrónica
> eso gastaría un consecutivo autorizado que no se recupera.

### 1.5 Grupo de afinidad

Es la clasificación que decide **si el contrato emite factura
electrónica o documento interno**, y con qué medio de pago se declara.

Es **de la empresa**, no de la sucursal: «corporativo» o «cortesía» no
cambian de significado al cruzar de ciudad.

> Un documento interno **no puede llamarse factura ni parecerlo**: usa
> su propia numeración y no lleva ninguno de los elementos de una
> electrónica. Esa distinción visual es lo que protege a quien lo
> emite.

El grupo del contrato se **congela en la factura** al emitirla. Si
mañana el contrato cambia de grupo, esa factura no se entera.

---

## 2. Qué es una factura aquí

### 2.1 Las tres fechas, que no son la misma

Esta es la confusión más frecuente. Una factura tiene **tres fechas** y
cada una responde algo distinto:

| Fecha | Qué responde | De dónde sale |
|---|---|---|
| **Período facturado** | *Qué* se está cobrando | Del **ciclo** de la sucursal (§3.2) |
| **Fecha de emisión** | *Cuándo* se expidió el documento | El día en que corrió la facturación |
| **Fecha de vencimiento** | *Hasta cuándo* hay plazo para pagar | Emisión + **días de plazo** (§3.4) |

Una factura emitida el 25 de septiembre puede estar cobrando octubre y
vencer el 15 de octubre. Las tres cosas a la vez, y ninguna se
contradice.

Hay una cuarta, que solo aparece cuando hay mora: la **fecha de
corte**, que es un aviso (§3.6).

### 2.2 Qué lleva dentro

1. **Un renglón por servicio del plan**, con su IVA y su parte del
   descuento si el contrato tiene uno vigente.
2. **Los cargos adicionales pendientes** del contrato (§2.4).
3. El **saldo anterior**, como información.

**Las facturas viejas NO se absorben.** Cada factura cobra únicamente
su período. Si el cliente debe septiembre y llega octubre, quedan **dos
facturas abiertas**, cada una cobrable por su lado. El total de lo que
debe se ve en el contrato, no dentro de una factura.

> Antes el sistema sí absorbía: refacturaba lo ya facturado dentro del
> documento nuevo. Eso es incompatible con la facturación electrónica
> —serían dos documentos declarando el mismo ingreso— y se eliminó.

### 2.3 Estados de la factura

| Estado | Qué significa |
|---|---|
| **Pendiente** | Emitida, sin pagos, dentro del plazo |
| **Pendiente Parcial** | Recibió un abono, todavía debe |
| **Pendiente con riesgo de corte** | Se emitió cuando el contrato ya tenía vencidas |
| **Vencida** | Se pasó la fecha de vencimiento sin pagarse |
| **Pagada** | Saldada con dinero |
| **Saldada con nota crédito** | Quedó en cero por una nota, **no porque entrara dinero** |
| **Anulada** | Se dejó sin efecto, con motivo y responsable |
| **Cargada a nueva factura** | Heredado del esquema viejo de absorción |

> **Pagada y Saldada con nota no son lo mismo**, y tenerlas separadas
> evita que un informe de recaudo cuente como cobrado algo que nunca
> entró a la caja.

### 2.4 Cargos adicionales

Cualquier cosa que no sea la mensualidad: instalación, un equipo, un
traslado, una reconexión. Se registran en el contrato y **entran solos
en la siguiente factura**. Dos formas:

- **De contado** — el monto completo en la próxima factura, y el cargo
  queda facturado.
- **Diferido a N cuotas** — entra **una cuota por mes** («cuota 3/12»).
  El cargo sigue pendiente hasta la última, y esa última ajusta el
  redondeo para que la suma sea exactamente el valor pactado.

### 2.5 Descuentos

El contrato puede llevar un descuento, en porcentaje o en valor fijo,
con una vigencia en meses.

- Se reparte **entre los servicios del plan**, en proporción a su
  precio. Ningún renglón queda en negativo y la suma es exactamente el
  valor pactado.
- **No toca los cargos adicionales**: una promoción sobre la
  mensualidad no rebaja la instalación ni un equipo.
- El IVA se calcula **sobre la base ya descontada**. Cobrar impuesto
  sobre dinero que no se cobró lo rechaza la DIAN.
- Se gasta **una vez por factura**, no una por renglón.

### 2.6 Saldo anterior y saldo a favor

Son cosas opuestas y se confunden:

- **Saldo anterior** — lo que el cliente **debe** de facturas
  anteriores que siguen abiertas. Sale impreso como información; no
  forma parte del valor de esta factura ante la DIAN.
- **Saldo a favor** — dinero **del cliente** que está en la casa: pagó
  de más, pagó adelantado, o una nota crédito superó lo que debía. Se
  aplica solo a las facturas siguientes, **empezando por las más
  antiguas**.

El saldo a favor nunca se guarda como un número suelto: se calcula
sumando sus movimientos, de modo que siempre se puede explicar de dónde
salió.

### 2.7 Numeración

Dos numeraciones distintas, y no se mezclan:

- **Documento interno** — una secuencia propia de la sucursal. La crea
  el sistema solo.
- **Factura electrónica** — un consecutivo de un **rango autorizado por
  la DIAN** en una resolución, con su prefijo y su vigencia. **No se
  inventa**: si no hay rango vigente, la factura no se emite. Emitir
  con un número que nadie autorizó es peor que no emitir.

Un consecutivo gastado **no se recupera**. Por eso el sistema se niega
a emitir facturas vacías y a emitir sin rango.

### 2.8 Notas crédito y débito

Una factura emitida **no se modifica nunca**. Si hay que corregirla se
emite un documento aparte:

- **Nota crédito** — rebaja el saldo: un servicio que se devolvió, un
  descuento concedido, un cobro de más.
- **Nota débito** — lo aumenta: intereses, un cargo que faltó.

Tienen su propio consecutivo y su motivo. Una nota crédito no puede
superar el saldo pendiente de la factura, y una factura anulada no
admite notas.

### 2.9 Anulación

Anular **no borra**: cambia el estado y registra quién, cuándo y por
qué. No se puede anular:

- una factura **con pagos** (habría dinero recibido sin documento que
  lo soporte: primero se reversan los pagos),
- una **electrónica que la DIAN ya validó** — esa se corrige con una
  nota crédito.

Al anular, si esa factura era una vencida que sumaba para el corte, el
contrato recupera su estado real de inmediato.

---

# PARTE II — LA CONFIGURACIÓN

## 3. Las reglas de la sucursal, campo por campo

**Dónde:** Gestión empresarial → Sucursales → editar → *Configuración
de facturación*.

### 3.1 Facturación del primer mes

| Opción | Qué hace |
|---|---|
| **Prorratear días restantes** (de fábrica) | Un contrato activado el día 20 paga solo del 20 al fin de mes |
| **Mes completo** | Paga el mes entero, entre el día que entre |

La cuenta del prorrateo es `precio × días cobrados ÷ días del mes`.
Solo afecta al **primer mes** de cada contrato; a partir del segundo se
cobra el mes completo siempre.

**Ejemplo.** Plan de $100.000, activado el 20 de un mes de 30 días:
`100.000 × 11 ÷ 30 = $36.666,67`.

### 3.2 Qué mes se cobra (el ciclo)

Es **qué período** cubre la factura. Con la corrida ejecutada el **25
de septiembre**:

| Opción | Período de la factura | Para quién |
|---|---|---|
| **Anticipado** | 1 al 31 de **octubre** | Lo habitual en un ISP: el cliente paga antes de consumir |
| **Mes en curso** (de fábrica) | 1 al 30 de **septiembre** | Lo que hacía gestISP históricamente |
| **Vencido** | 1 al 31 de **agosto** | El cliente paga lo ya consumido |

Mueve **solo el período**. La emisión sigue siendo el día de la corrida
y el vencimiento se cuenta desde ahí.

> **Decida esto al montar la sucursal.** Cambiarlo con clientes
> facturando deja un mes sin cobrar o intenta cobrarlo dos veces
> (caso 11).

### 3.3 Cómo se factura, y el día

- **Manual** — las facturas salen solo con el botón *Generar facturas*.
- **Automática** — la tarea diaria las lanza sola el día indicado. Con
  **31**, en los meses cortos corre el último día: quien pone 31 está
  diciendo «el último día del mes».

En automática una sucursal no factura dos veces el mismo período aunque
la tarea corra varias veces.

### 3.4 Días de plazo

Días desde la emisión hasta el vencimiento. Con 20: factura del 25 de
septiembre → vence el 15 de octubre. Pasado el vencimiento sin pago, la
factura pasa a **Vencida** sola.

### 3.5 Umbral de corte

**Cuántas facturas vencidas hacen falta para suspender el contrato.**
Con 2, al llegar a la segunda vencida el contrato pasa a **Suspendido**
y se le corta el servicio.

**Es la única condición que corta de verdad.**

### 3.6 Días hasta el corte

**Es la fecha de corte que se le ANUNCIA al cliente.** Cuando se emite
una factura a un contrato que ya tiene vencidas:

- la factura nace *Pendiente con riesgo de corte*,
- se calcula `emisión + estos días` y esa fecha sale impresa en la
  factura (columna «FECHA DE CORTE»),
- el contrato pasa a **Pre-suspensión** con esa fecha como aviso.

> **Advertencia.** Hoy esa fecha es **informativa**: ningún proceso la
> mira para cortar. Quien corta es el **umbral**. Con umbral 2 y 24
> días de aviso, el contrato se suspende en cuanto tenga 2 vencidas,
> aunque la fecha anunciada no haya llegado. Conviene que los dos
> números cuenten la misma historia.

Al pagar, el aviso se borra.

### 3.7 Precio de reconexión

No está en este bloque sino en los datos de la sucursal, pero pertenece
a la facturación: es lo que se le cobra al cliente **cortado que paga**
(§4.6).

---

# PARTE III — EL TRABAJO DE TODOS LOS MESES

## 4. Flujos paso a paso

### 4.1 Montar una sucursal para facturar (una vez)

1. **Gestión empresarial → Sucursales → editar**.
2. En *Configuración de facturación*, decidir las seis reglas de la
   sección 3.
3. Guardar. Si todas las sedes cobran igual: **Empresas → ficha →
   Aplicar a todas**.
4. Revisar que los **planes** tengan servicios con precio, IVA y código
   de producto.
5. Si va a emitir electrónica: certificado, resolución y rango
   registrados, y la empresa habilitada
   ([Habilitacion-DIAN.md](Habilitacion-DIAN.md)).

### 4.2 Dar de alta un cliente

1. **Gestión de clientes → Creación de cliente**.
2. Desde su ficha, **crear el contrato**: plan, dirección, grupo de
   afinidad y **fecha de activación**.
3. Mientras el contrato esté *Por Instalar* **no se factura**. Entra en
   las corridas cuando pasa a *Activo*, normalmente al cerrar la orden
   técnica de instalación.

> **La fecha de activación es la que manda para el primer mes.** Si se
> registra mal, el prorrateo sale mal y la primera factura es la que el
> cliente mira con lupa.

### 4.3 Facturar el mes

**En manual:**

1. **Facturación → Facturas**.
2. Botón **Generar facturas** y confirmar.
3. Al terminar lleva al **detalle de la corrida**: generadas, omitidas
   y totales, con el reporte descargable.
4. Revisar las **omitidas**. «Ya tiene factura de ese período» es lo
   normal; **«nada que facturar» no lo es** — ese contrato se quedó sin
   plan.

**En automática:** no hay que hacer nada; conviene mirar el listado de
corridas una vez al mes para confirmar que salió.

**Qué hace la corrida, por dentro:**

1. Marca **vencidas** las facturas cuyo plazo ya pasó (regla global, no
   depende de la sucursal).
2. Refresca las **suspensiones de esta sucursal**: cuenta vencidas y
   suspende a quien llega al umbral. Los contratos terminados
   —retirado, anulado, cedido— nunca se suspenden, aunque deban.
3. **Registra la corrida**, rotulada con el **período que va a cobrar**.
4. Recorre los contratos facturables y emite la factura de cada uno.
5. Cierra la corrida con sus totales.

Un contrato que falle **no aborta la corrida**: queda en el log y se
sigue con el resto.

### 4.4 Que le llegue al cliente

Al emitirse, cada factura dispara su aviso: **correo** con el PDF y
**WhatsApp** con el enlace de descarga. Si el contrato es de un grupo
electrónico, además se arma el XML, se firma y se transmite a la DIAN
([Transmision-DIAN.md](Transmision-DIAN.md)); cuando la DIAN la acepta,
al cliente le llega el paquete completo.

### 4.5 Cobrar

1. **Facturación → Cobranza → Cobrar**.
2. Buscar el contrato y marcar las facturas que paga. Se pueden pagar
   **varias de un contrato** en un solo cobro.
3. **Hace falta caja abierta**, sea cual sea el método de pago —
   también en transferencia. Cada peso recaudado queda en una caja y en
   el cuadre del punto de cobro.
4. El monto no puede pasar del saldo pendiente. Si el cliente da de
   más, el sobrante queda como **saldo a favor**.
5. Si hay **retenciones**, se registran con el pago: **saldan la
   factura pero no entran a la caja**, porque ese dinero no lo recibe
   la empresa, lo consigna el cliente al Estado.
6. Sale el **recibo por contrato**, listo para la impresora térmica.

**Qué pasa con el contrato al pagar:**

- Pago parcial → factura *Pendiente Parcial*, el contrato no cambia.
- Pago total estando en **Pre-suspensión** → vuelve a **Activo**.
- Pago total estando **Suspendido** → pasa a **Por Reconexión**, se le
  crea la **orden técnica** y se le cobra la reconexión (§4.6).

### 4.6 Reconexión

Cuando un cliente cortado paga:

1. Se le crea un **cargo de reconexión** con el precio de la sucursal
   —uno por corte, no uno por factura—, que entrará en su próxima
   factura con el mismo IVA que su servicio principal.
2. Se le **reactivan la ONT y la PPPoE** automáticamente.
3. El contrato queda *Activo* o *Por Reconexión* según lo que el equipo
   haya confirmado.

### 4.7 Corregir una factura

- **Cobró de más / hay que rebajar** → **nota crédito** desde la
  factura.
- **Faltó cobrar algo** → **nota débito**, o un cargo adicional si es
  para el mes siguiente.
- **La factura no debió existir** → **anular** (solo si no tiene pagos
  y la DIAN no la validó).

### 4.8 Dar de baja un contrato

Al cerrar una orden de retiro —o con una orden administrativa— el
contrato pasa a *Retirado* y, **antes**, el sistema emite una última
factura con **todo lo que quedaba por cobrar de sus cargos**: el saldo
completo de cada uno, no una cuota más.

**No se devuelve nada del mes en curso.** Si el mes ya se facturó y el
cliente se va el día 10, esa factura queda como está; devolver los días
no usados es una decisión comercial y se hace con una nota crédito.

### 4.9 Mora y corte

No hay nada que hacer a mano: la tarea diaria marca vencidas y suspende
a quien llega al umbral. Lo que sí conviene:

1. Revisar **Informes gerenciales → Facturación y recaudo**.
2. Para un corte puntual de una lista de morosos: **Gestión técnica →
   Cortes masivos**, que revalida la deuda antes de cortar.

---

# PARTE IV — CASOS RESUELTOS

## 5. Casos de uso

En todos, plan de **$100.000** sin IVA, salvo que se diga otra cosa.

### Caso 1 — Cliente nuevo antes de la corrida
Corrida el 25. Activado el **10 de septiembre**. Prorrateo, mes en
curso.
→ Entra en la corrida del 25 con período **10 al 30 de septiembre**.
`100.000 × 21 ÷ 30 = $70.000`. En octubre, mes completo.

### Caso 2 — Cliente nuevo DESPUÉS de la corrida
Corrida el 25. Activado el **26 de septiembre**. Prorrateo.
→ En septiembre **no hay factura**: cuando corrió, ese contrato no
existía.
→ La de octubre cubre **26 sep al 31 oct**, 36 días:
`100.000 × 36 ÷ 31 = $116.129,03`.
→ Desde noviembre, mes completo.

### Caso 3 — El mismo caso, con mes completo
→ En septiembre no hay factura. La de octubre es **octubre completo**,
$100.000. Los 5 días de septiembre **los regala la empresa**: es la
decisión que se tomó al elegir «mes completo».

### Caso 4 — Activación vieja sin facturas
Contrato activado el **10 de febrero** que nunca se facturó, y hoy
corre octubre.
→ **No se arrastra nada**: período octubre completo, $100.000.
→ Es a propósito. Ocho meses sin factura no es un cliente que deba ocho
meses: es un dato torcido. Si de verdad hay que cobrar lo anterior, se
hace con un **cargo adicional**, a mano y mirándolo.

### Caso 5 — ISP que cobra por adelantado
Ciclo **anticipado**, corrida el 25 de septiembre, 20 días de plazo.
→ Período **1 al 31 de octubre**, emitida el 25 de septiembre, vence el
15 de octubre.

### Caso 6 — Cliente con una factura vencida
Debe septiembre y llega la corrida de octubre.
→ La de octubre nace *Pendiente con riesgo de corte*, con fecha de
corte impresa, y el contrato pasa a **Pre-suspensión**.
→ La de septiembre **sigue abierta**. Ahora hay dos facturas.

### Caso 7 — Llega al umbral
Umbral 2. Se vence la segunda.
→ La siguiente corrida —o la tarea diaria— lo pasa a **Suspendido** y
se le corta.
→ Al pagar: reconexión cobrada, ONT y PPPoE reactivadas, orden técnica
creada.

### Caso 8 — Paga seis meses por adelantado
Se registra el pago; el sobrante queda como **saldo a favor**.
→ Cada mes, la factura nueva se salda sola con ese saldo hasta
agotarlo. El cliente no vuelve a pasar por caja hasta que se acabe.

### Caso 9 — Instalación a cuotas
Instalación de $240.000 diferida a 12 cuotas.
→ Cada factura mensual lleva **dos renglones**: la mensualidad y
«Instalación (cuota N/12)» por $20.000.
→ Si el cliente se retira en la cuota 5, la factura de liquidación
cobra **las 7 que faltan de una vez**.

### Caso 10 — Contrato sin plan
Alguien borró el plan que usaba.
→ La corrida lo **omite**: «nada que facturar». No se emiten facturas
de cero renglones.

### Caso 11 — Cambiar el ciclo a mitad de año
De **mes en curso** a **anticipado**, cambiando en septiembre:
- La corrida de septiembre ya cobró septiembre.
- La de octubre cobrará **noviembre**.
- **Octubre se queda sin cobrar.**

Al revés (de anticipado a en curso) se intentaría cobrar el mismo mes
dos veces; el candado de período lo impide y la corrida sale en cero.
→ **Conclusión:** el ciclo se decide al montar la sucursal. Cambiarlo
después exige una factura de ajuste y saber lo que se hace.

### Caso 12 — Cliente que se queja de que le cobraron de más
Ruta de revisión, en orden:
1. Mirar el **período** impreso en la factura: ¿cuántos días cubre?
2. ¿Es su **primera** factura? Puede llevar días arrastrados (caso 2).
3. ¿Tiene **cargos adicionales**? Salen como renglones aparte.
4. ¿Se le venció un **descuento**? Su vigencia es en meses.
5. ¿Es una **reconexión** recién cobrada?

### Caso 13 — Se emitió una factura que no debía
- Sin pagos y no validada por la DIAN → **anular**, con motivo.
- Validada por la DIAN → **nota crédito** por el total.
- Con pagos → primero reversar los pagos.

---

# PARTE V — CUANDO ALGO NO SALE

## 6. Diagnóstico

### «No se generó la factura de este contrato»

Por orden de probabilidad:

1. **El estado no factura** — por instalar, suspendido, retirado (§1.3).
2. **Ya tiene factura de ese período** — es el candado que impide
   duplicar. Con ciclo anticipado el período no es el mes en curso.
3. **No hay nada que facturar** — contrato sin plan y sin cargos.
4. **Su fecha de inicio de facturación es posterior** al período.
5. **La corrida no incluyó su sucursal** — la corrida es por sucursal.

### «Salió más cara de lo normal»

Días arrastrados en la primera factura, cargos adicionales, una cuota
diferida, un descuento vencido o la reconexión. Ver caso 12.

### «El cliente pagó y sigue cortado»

El pago total de un suspendido lo pasa a **Por Reconexión**, no
directamente a Activo: espera la confirmación del equipo. Revisar que
la ONT y la PPPoE se hayan reactivado.

### «No se cortó al que debe»

- ¿Llegó al **umbral**? Es el número de facturas **Vencidas**, no de
  pesos.
- ¿Sus facturas están marcadas **Vencidas**? Solo lo hace la tarea
  diaria o una corrida.
- ¿El contrato está en un estado **final**? Esos nunca se suspenden.
- Recordar: la **fecha de corte impresa no corta** (§3.6).

### «La corrida dice que generó menos de las esperadas»

El detalle de la corrida lista las omitidas con su motivo. Ese es el
sitio donde mirar, no el listado de facturas.

---

## 7. Lo que el sistema NO hace

Anotado para que nadie lo dé por hecho:

- **La fecha de corte anunciada no corta.** Corta el umbral.
- **Solo se prorratea el primer mes.** A partir del segundo, mes
  completo siempre.
- **No arrastra más de un mes** de días sin cobrar.
- **No devuelve días** al dar de baja a mitad de mes.
- **No absorbe facturas viejas** en la nueva.
- **Cambiar el ciclo no ajusta lo ya facturado.**
- **No factura contratos suspendidos.**

---

## 8. Glosario

| Término | Qué es |
|---|---|
| **Corrida** | Una pasada de facturación de una sucursal |
| **Período facturado** | El rango de días que cubre la factura |
| **Ciclo** | Si se cobra el mes siguiente, el actual o el anterior |
| **Prorrateo** | Cobrar solo los días de servicio del primer mes |
| **Arrastre** | Días anteriores al período que nadie había cobrado, en la primera factura |
| **Umbral de corte** | Número de facturas vencidas que suspende el contrato |
| **Saldo anterior** | Lo que el cliente debe de facturas previas |
| **Saldo a favor** | Dinero del cliente que queda en la casa |
| **Grupo de afinidad** | Clasificación que decide electrónica o interna |
| **Rango autorizado** | Los consecutivos que la DIAN permite usar |
| **Nota crédito** | Documento que rebaja el saldo de una factura |
| **Documento interno** | Factura no electrónica; no puede parecerlo |

---

## 9. Dónde está esto en el código

| Qué | Dónde |
|---|---|
| Decide el período y arma la factura | `app/Billing/Services/InvoiceGenerator.php` |
| Qué mes cobra cada ciclo | `app/Billing/Enums/BillingCycle.php` |
| Mes completo o prorrateo | `app/Billing/Enums/ProrationMode.php` |
| La corrida de una sucursal | `app/Billing/Services/MonthlyBillingRun.php` |
| Vencidas y suspensiones | `app/Billing/Services/OverdueProcessor.php` |
| La corrida automática diaria | `app/Console/Commands/RunDailyBilling.php` |
| Configuración de la sucursal | `app/Models/BranchBillingSetting.php` |
| Pagos, caja y transiciones | `app/Billing/Services/PaymentRegistrar.php` |
| Saldo a favor | `app/Billing/Services/CreditBalanceService.php` |
| Reconexión al pagar | `app/Billing/Services/ServiceReconnection.php` |
| Notas crédito y débito | `app/Billing/Services/NoteIssuer.php` |
| Anulación | `app/Billing/Services/InvoiceVoider.php` |
| Factura de liquidación | `app/Billing/Services/ContractLiquidator.php` |
| Electrónica o interna | `app/Billing/Services/ElectronicInvoicingDecider.php` |
| Numeración | `app/Billing/Services/InvoiceNumerator.php` |

Las pruebas que fijan este comportamiento están en
`tests/Feature/Billing/`: `BillingCycleTest` (ciclo y arrastre),
`InvoiceGenerationTest` (generación), `ProratedInvoiceTotalsTest` (que
las cuentas de un mes prorrateado cuadren para la DIAN),
`AutomaticBillingTest` (la corrida sola) y `PaymentsTest` (cobros).

---

*Este manual se genera en PDF con `php artisan docs:pdf Facturacion`.
Si cambia el comportamiento, se corrige el `.md` y se vuelve a generar:
el PDF no se edita a mano.*
