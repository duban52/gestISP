# Multiempresa: cómo se aísla una empresa de otra

Plan completo: [`Plan-Empresa-Sucursales-Grupos.md`](Plan-Empresa-Sucursales-Grupos.md)
Pruebas: [`tests/Feature/Companies/`](../tests/Feature/Companies/)

## La jerarquía

```
Company   ← el contribuyente. Un NIT. Aquí vive lo FISCAL
   └── Branch   ← la sede. Aquí vive lo OPERATIVO
        └── clientes · contratos · facturas · red · caja · almacén
```

La regla para decidir dónde va un dato nuevo: **si la DIAN lo relaciona con el
NIT, es de la empresa; si describe cómo trabaja una sede concreta, es de la
sucursal.**

## De dónde salió cada empresa

Antes no existía el concepto: `branches` llevaba su propio NIT, así que en el
modelo de datos **sucursal era contribuyente** — y el NIT estaba duplicado en
cada sede, pudiendo divergir.

La migración creó **una empresa por cada NIT distinto**. Las sucursales que
comparten NIT quedaron bajo la misma empresa, que es lo que eran en realidad.

El relleno va **dentro** de la migración, no en un comando posterior: si la
columna se creara vacía y el relleno fuera manual, un despliegue a medias
dejaría sucursales sin empresa y la base inconsistente. O pasa todo, o no pasa
nada.

## Las dos restricciones que lo impedían

```
branches.name  UNIQUE global  →  UNIQUE (company_id, name)
plans.name     UNIQUE global  →  UNIQUE (branch_id, name)
```

Con las anteriores, dos empresas **no podían** tener cada una su «Sucursal
Principal» ni su plan «100 Megas». Era un bloqueo físico, no de código.

## Cómo funciona el aislamiento

### Una sola barrera, la de empresa

El filtro por **sucursal** sigue siendo el de siempre: escrito a mano, 113 veces
en 31 controladores. No se le puso un scope encima porque duplicaría el filtrado
y rompería los sitios que cruzan sucursales a propósito.

Lo que se añadió es la barrera que **no existía**: la de empresa. Es un global
scope, así que se aplica a toda consulta —incluidas las que alguien escriba
mañana y se olvide de acotar. Esa es la gracia: no depende de que nadie se
acuerde.

De paso, mejora las consultas que cruzan sucursal a propósito. `PppoeMassCutoff`
busca en **otras** sucursales para decir «esto está en otra sede»; antes podía
encontrar una sucursal de otra empresa, y ahora solo ve las de la suya.

### Por qué `company_id` está desnormalizado

`branch_id` ya determinaba la empresa: bastaría un `JOIN`. Se duplica igualmente
por dos razones:

1. **Coste.** El scope corre en *todas* las consultas del sistema. Un
   `where company_id = X` es gratis; un `whereHas('branch', …)` mete una
   subconsulta en cada listado, informe y exportación.
2. **Independencia.** La barrera de empresa tiene que sostenerse aunque el
   filtro de sucursal se apague — y hay sitios donde apagarlo es legítimo.

**El riesgo de duplicar un dato es que diverja.** Eso no se deja al criterio de
quien escriba el código: el trait deriva `company_id` de `branch_id` **en cada
guardado**. No hay vía de escritura normal que los descuadre.

### Qué tablas la llevan y cuáles no

**La llevan (26):** las que pertenecen a un contribuyente y se consultan
directamente — clientes, contratos, facturas, notas, pagos, cajas, órdenes,
OLT, ONT, PPPoE, routers, red óptica, materiales, almacenes, planes, servicios,
auditoría, secuencias de numeración.

**No la llevan:**
- Las **hijas** (`invoice_items`, `payments`, `nap_ports`, `ont_metrics`,
  `technical_order_materials`…): heredan el contexto de su padre. Añadírsela
  sería duplicar dos veces el mismo dato.
- `user_branch`, que es un pivote hacia `branches`.
- `branch_billing_settings`, que es 1:1 con la sucursal.

### `Branch` es un caso aparte

Lleva scope propio y **no** el trait. El trait deriva `company_id` de
`branch_id` al guardar, y una sucursal no tiene `branch_id`: acabaría tomando la
empresa del contexto y pisando la que resuelve el puente del NIT — que corre en
`creating`, **después** de `saving`.

## El contexto

`App\Tenancy\CurrentContext` es de donde el scope saca «quién está mirando».

```php
$contexto->companyId();      // siempre, si está activo
$contexto->branchId();       // null = panel consolidado
$contexto->branchIds();      // las sucursales que este usuario ve aquí
$contexto->permiteSucursal($id);   // valida un branch_id que llegue de fuera
```

Lo activa el middleware `SetCompanyContext`, que va **después** de
`EnsureBranchSession` —quien deja la sucursal en la sesión— y **antes** de
cualquier consulta de datos.

**La empresa nunca se lee de la petición.** Se deduce de la sucursal, y la
sucursal ya venía validada contra las del usuario. Aceptar un `company_id` de
fuera sería darle la llave al que llama a la puerta. Hay una prueba que manda
`?company_id=` de otra empresa y comprueba que se ignora.

### Sin contexto no se filtra, y es deliberado

En consola y en los trabajos en cola el contexto no se activa. Un poller de ONT
o una corrida de facturación tienen que poder recorrer varias empresas; si el
scope filtrara con un contexto vacío, **dejarían de ver nada y nadie se
enteraría hasta que faltara una corrida de facturación**.

La contrapartida: el código que corre así acota por su cuenta. Los trabajos
reciben la empresa de forma explícita y nunca la leen de la sesión.

### Los dos escapes, y por qué son explícitos

```php
Modelo::sinFiltroDeEmpresa()->...                        // una consulta
app(CurrentContext::class)->sinContexto(fn () => ...)    // un bloque
```

`sinContexto()` **restaura siempre**, incluso si lo de dentro lanza una
excepción. Si se quedara apagado, todo lo que viniera después quedaría sin
barrera sin que nadie lo notara. Hay una prueba de eso.

## `@can` ahora mira el rol de la sucursal activa

Un usuario puede tener un rol distinto en cada sucursal — eso guarda
`user_branch.role_id`, y es el criterio del middleware `check.permission` y del
filtro del menú.

Pero `@can` en las vistas **no usaba ese rol**: sin un `Gate::before`, Laravel
delega en spatie/laravel-permission, que evalúa los roles **globales**. Los dos
pueden divergir, y alguien con rol alto en una sucursal veía botones que en la
activa no le corresponden.

Se compensaba exigiendo el permiso también en el controlador, que es lo que de
verdad protegía. Con varias empresas eso deja de bastar: el botón de más deja de
ser cosmético cuando detrás hay datos de otro contribuyente.

`Gate::before` devuelve `null` (y no `false`) cuando no hay rol en sesión: deja
que Laravel siga con sus comprobaciones normales en vez de negar todo, que es lo
que rompería el login y el restablecimiento de contraseña.

## Un cambio de comportamiento que conviene conocer

| Acceso | Antes | Ahora |
|---|---|---|
| Otra sucursal, misma empresa | 403 Prohibido | 403 Prohibido |
| **Otra empresa** | 403 Prohibido | **404 — no existe** |

El 404 es mejor: un 403 confirma que el registro existe.

Esto hizo cambiar de significado a diez pruebas que decían «otra sucursal».
Como `BranchFactory` crea una empresa por sucursal, su segunda sucursal pasó a
ser otra empresa. Se les fijó la empresa para que vuelvan a probar lo que
querían: el aislamiento **entre sedes de una misma empresa**, que es un caso
distinto y el que esas pruebas defendían.

Se ve claro en `PppoeMassCutoffTest`: el corte masivo distingue «está en otra
sucursal» de «no existe», y eso solo tiene sentido dentro de la misma empresa —
entre empresas distintas la respuesta correcta es «no existe».

**Al escribir una prueba nueva con dos sucursales, decide cuál de los dos casos
quieres** y ponle `company_id` si es el primero.

## El barrido de `session('branch_id')`

Este es el trabajo grueso de la fase 4, y conviene entender por qué hizo falta.

Antes del panel consolidado, **siempre** había una sucursal activa. Eso hizo
que `session('branch_id')` se usara indistintamente para tres preguntas muy
distintas, que en consolidado tienen tres respuestas distintas:

| La pregunta | Cómo se escribía | Cómo se escribe ahora |
|---|---|---|
| ¿Qué registros veo? | `where('branch_id', session('branch_id'))` | `whereIn('branch_id', $contexto->branchIds())` |
| ¿Puedo tocar esto? | `(int) $x->branch_id === (int) session('branch_id')` | `$contexto->permiteSucursal($x->branch_id)` |
| ¿Dónde lo guardo? | `'branch_id' => session('branch_id')` | `$contexto->branchParaEscritura($request->input('branch_id'))` |

Con la sesión a null —que es lo que hay en panel consolidado— cada una fallaba
de una forma:

- **Los filtros** se convertían en `branch_id = null` y **no devolvían nada**:
  el módulo aparecía vacío, como si no hubiera datos.
- **Las autorizaciones** fallaban siempre, así que en consolidado se daba
  **403 en todo**.
- **Las escrituras** guardaban `branch_id` nulo. En veinticuatro tablas eso
  revienta con un error de la base —feo, pero seguro—; en `invoices` y
  `cash_registers`, que lo admiten nulo, habría creado una factura o una caja
  **sin sucursal**, invisible en todos los listados y en toda la facturación.

### La trampa del `whereIn` vacío

Es la parte del barrido que más cerca estuvo de colarse, y conviene tenerla
presente al tocar cualquiera de estos filtros:

```php
whereIn('branch_id', [])   // NO es «no filtres». Es «escóndelo todo».
```

`branchIds()` viene vacío exactamente cuando **no hay contexto**: una tarea en
cola, un comando de consola, el sondeo de los routers. Ninguno pasa por el
middleware que lo establece.

El sitio que se cambió decía antes `if ($branchId) { where(...) }` — sin
sucursal, sin filtro. Al pasarlo a `whereIn` se convirtió en un filtro
imposible: una corrida de facturación habría recorrido **cero contratos sin dar
ningún error**, produciendo un resultado vacío en silencio.

La regla es la misma que ya estaba tomada para el global scope —*sin contexto
no se filtra, y es deliberado*— y ahora vive en un solo sitio:

```php
$contexto->limitarSucursales($query);                         // branch_id
$contexto->limitarSucursales($query, 'contracts.branch_id');  // con join
```

Los controladores siguen escribiendo el `whereIn` a mano y no pasa nada: todos
corren detrás de `SetCompanyContext`, que garantiza contexto en toda petición
de un usuario. Quien lo necesita es la capa que se llama **fuera** de una
petición — `ContractQuery`, `PppoeQuery`, los scopes `deSucursal` y los
informes. Lo defiende `NoContextQueryTest`.

### Cosas que aparecieron al barrer

El barrido destapó cuatro fallos que no eran del modo consolidado, sino de
antes:

1. `CashRegisterController::report()` pasaba la sucursal **dentro de**
   `with(['transactions', 'user', $branchId])`, es decir, como si fuera el
   nombre de una relación a cargar. El informe de caja **no filtraba por
   sucursal en absoluto**.
2. El listado de facturas exigía `clients.branch_id`. Desde que el cliente
   pertenece a la empresa esa columna es opcional, así que las facturas de
   clientes creados en consolidado **desaparecían del listado**. Ahora se
   filtra por la sucursal del contrato, que es donde se presta el servicio.
3. `PaymentController` y `InvoiceController` protegían el filtro con
   `session()->has('branch_id')`. `has()` devuelve **false cuando el valor es
   null**, así que en consolidado el registro de pagos se quedaba **sin filtro
   de sucursal ninguno** y el total por recaudar salía en cero.
4. La orden técnica de reconexión que genera un pago se creaba en la sucursal
   de quien cobra. Ahora se crea en la del **contrato**: es el técnico de esa
   zona el que tiene que ir.

### Lo que se deduce en vez de preguntarse

No todo lo que escribe necesita preguntar. Cuando la sucursal se puede deducir
del propio dato, se deduce — es la misma regla que el proyecto aplica a la
ocupación de puertos NAP y al uso de hilos:

| Qué se crea | De dónde sale su sucursal |
|---|---|
| Cuenta PPPoE | Del router donde se crea el secret |
| ONT | De la OLT a la que se conecta |
| Corrida de importación de ONTs | De la OLT que se está leyendo |
| Lote de pagos | De la caja abierta: donde está la caja entra el dinero |
| Orden de reconexión | Del contrato que se reconecta |
| Todo lo que cuelga de una red óptica | De la red (muflas, cajas, cables) |

### El selector

`<x-selector-sucursal />` es un componente Blade que consulta el contexto por
su cuenta, así que se añade a cualquier formulario sin tocar su controlador.
Se pinta **solo cuando hay algo que elegir**: panel consolidado y más de una
sucursal alcanzable. Con una sola —empresa de una sede, o usuario con acceso a
una— no aparece y el controlador la asume.

Quien decide es `CurrentContext::hayQueElegirSucursal()`, el mismo criterio que
usa `branchParaEscritura()` en el servidor. Un solo criterio, no dos: el
formulario nunca esconde un campo que el servidor luego vaya a exigir.

Lo llevan: plan, servicio, material, categoría, almacén, red óptica, OLT,
router, contrato, importación de clientes, apertura de caja, cortes masivos,
corrida de facturación y PDF masivo. Los informes gerenciales llevan el suyo
propio en la barra de filtros, porque ahí no es un campo obligatorio de alta
sino un filtro con valor actual.

### Cuando falta la sucursal

`branchParaEscritura()` lanza dos excepciones distintas a propósito:

- **`RuntimeException`** si no hay contexto. Eso es un fallo del programa —el
  middleware debería haberlo impedido— y un 500 es la respuesta correcta.
- **`SucursalNoIndicada`** si estamos en consolidado y no vino sucursal. Eso no
  es un fallo: es un dato que falta. La clase define `render()`, así que
  Laravel la convierte sola en un error junto al campo `branch_id` (o un 422 si
  la petición es JSON), sin necesidad de envolver cada llamada en un
  `try/catch`. Hereda de `RuntimeException`, así que quien la capturara por el
  tipo de antes la sigue capturando.

## El rol en panel consolidado

Un usuario puede tener un rol distinto en cada sucursal — es lo que guarda
`user_branch.role_id`. En modo independiente no hay duda: manda el de la
sucursal en la que entró. El panel consolidado abarca varias a la vez y hay que
quedarse con **uno solo**, porque toda la autorización se resuelve contra
`session('current_role_id')`.

Se tomaba el de la **primera sucursal de la lista**, y eso no era una
simplificación inocente: quien fuera administrador en la sede A y solo consulta
en la B pasaba a administrar TAMBIÉN la B en cuanto entraba en consolidado. Una
escalada de privilegios que dependía del orden de una lista.

**Ahora se trabaja con el menos privilegiado de sus roles.** Consolidado amplía
lo que se ve, nunca lo que se puede hacer: si en alguna de las sedes que está
mirando solo puede consultar, consulta en todas. Para actuar con más permisos
hay que entrar en esa sucursal concreta, que es donde de verdad los tiene.

El privilegio se mide por número de permisos. Es una aproximación, pero en este
sistema los roles son acumulativos (técnico ⊂ auxiliar ⊂ administrador ⊂
superadministrador), así que ordena bien; con empate manda el id menor para que
la respuesta no dependa del orden. El usuario ve con qué rol está trabajando en
el menú superior, así que no se le aplica en silencio. Lo defiende
`ConsolidatedRoleTest`.

## La columna «Sucursal» en los listados

Trabajando en consolidado, los listados mezclan filas de varias sedes. Sin
decir de cuál es cada una, se cobra la factura de otra sede o se manda un
técnico a la ciudad equivocada.

La columna aparece **solo cuando aporta**: panel consolidado y más de una
sucursal alcanzable (`CurrentContext::mostrarSucursal()`). Con una sola sede
repetiría el mismo valor en todas las filas, y en móvil empujaría las columnas
útiles fuera de la pantalla.

Se consigue de tres formas según lo que sea la pantalla:

| Tipo de pantalla | Cómo |
|---|---|
| **DataTables** (la mayoría) | La columna se pinta SIEMPRE, la primera, y se esconde con `visible: false` |
| **Tablas simples** (muflas, cables, categorías) | Se condiciona en Blade con `@if($mostrarSucursal)` |
| **Contratos** | Entra en su selector de columnas; viene marcada de serie en consolidado |
| **Redes** (son tarjetas) | Un distintivo junto al nombre |

**Por qué en DataTables se pinta siempre y se esconde.** El JS de esas tablas
lleva índices numéricos de columna (`columnDefs targets`, `order`). Si la
columna apareciera y desapareciera según el modo, esos índices apuntarían a una
columna distinta según quién mire la pantalla. Un `columnDef` oculto sigue
contando para los índices, que es exactamente lo que hace falta. Al añadirla se
desplazaron **+1 todos los índices numéricos** de las trece tablas afectadas.

`$mostrarSucursal` llega a las vistas por un view composer (`AppServiceProvider`)
y no pasándolo desde cada controlador: son más de veinte listados, y añadir uno
nuevo y olvidarse de la variable dejaría la vista rota en vez de simplemente sin
columna.

**Dónde NO se muestra, a propósito:** en clientes (el cliente pertenece a la
empresa y su `branch_id` es opcional, así que la columna vendría casi siempre
vacía), en ONTs sin autorizar (primero se elige la OLT, y con ella queda dicha
la sucursal) y en caja (es la caja abierta de quien mira, y una caja se abre en
una sola sede por definición).

Lo defiende `BranchColumnTest`, que recorre las veinte pantallas de verdad.

## Grupos de afinidad

Implantados en la fase 5. Clasifican los contratos de la empresa y deciden por
qué camino sale la factura de cada uno. Cuelgan de la **empresa** y no de la
sucursal, como todo lo fiscal.

El detalle está en [Grupos-de-Contrato.md](Grupos-de-Contrato.md) — incluidas
dos trampas de MySQL con las columnas generadas que se manifiestan con un
mensaje que no tiene nada que ver con el problema real.

## Lo que todavía NO hace

- `branches.nit` sigue existiendo porque se imprime en **siete** plantillas
  (PDF de facturas y de pendientes, recibo térmico, correos, layout de PDF y el
  CRUD). Se retira cuando esas apunten a `companies.document_number`.
- La auditoría lleva `company_id` y está acotada, pero las filas de sistema sin
  sucursal quedan con empresa nula y no se ven en la pantalla. Hay que
  revisarlo cuando esa pantalla se haga consciente de la empresa.
- Los **cortes masivos** siguen siendo de una sucursal: en consolidado se
  pregunta cuál, y la revisión sigue avisando «pertenece a otra sucursal» para
  los identificadores que caen fuera. No se cortan varias sedes de una tanda.

## Los informes gerenciales sí suman sedes

Es la excepción a la regla anterior, y va aparte porque es el único módulo
donde el panel consolidado hace algo más que ampliar el alcance.

Los cuatro informes —crecimiento, técnicas, facturación, aprovisionamiento—
recibían `?int $branchId`. Ahora reciben `int|array|null`: una sucursal, varias
o ninguna. Se sigue admitiendo el entero suelto porque es como los llamaban las
decenas de sitios y pruebas que ya existían, y no había motivo para romperlos;
`BranchFilter::normalizar()` lo convierte todo a lista.

En panel consolidado la barra de filtros pinta una ficha por sucursal. Se
marcan las que entran y se dejan fuera las que no; **ninguna marcada equivale a
todas**, que es lo que espera quien vacía las fichas por error y también el
valor por defecto al entrar. Viajan por la URL como los demás filtros, así que
el botón de PDF se las lleva sin hacer nada.

**La línea que no se cruza.** Antes la sucursal salía siempre de la sesión y
nunca de la petición, a propósito: así nadie pedía el informe de una sede ajena
cambiando la URL. Ahora sí viene de la petición, pero se cruza con el alcance
del usuario (`array_intersect`) antes de usarla. Lo que no esté entre sus sedes
se cae solo, y si no queda ninguna se informa de todas las suyas — nunca de una
ajena. Es lo que más se prueba en `ReportBranchScopeTest`.

En modo independiente no cambia nada: manda la sucursal activa y lo que llegue
en la URL se ignora.
- La **auditoría** de una acción hecha fuera de una petición HTTP se apoya en
  la sesión, no en el contexto: ahí no ha corrido el middleware que lo
  establece.
