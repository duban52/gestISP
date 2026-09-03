# Grupos de afinidad

Fase 5 de multiempresa. Es la pieza que clasifica los contratos de una empresa
y decide, contrato a contrato, **qué documento emite**: factura electrónica
—con firma digital y numeración autorizada— o documento interno.

Hoy esa decisión **todavía no tiene consecuencias técnicas**: el camino
electrónico no existe hasta la fase 9. El grupo se implanta antes a propósito,
para que cuando esa fase llegue los contratos ya estén clasificados y no haya
que clasificar la cartera entera a la carrera.

## Por qué se llama así

Se descartaron dos alternativas:

- **«Tipo de facturación»** ata el nombre a un solo uso, y la idea es poder
  colgar más reglas del grupo con el tiempo.
- **«Categoría»** ya existe en el proyecto (`Category`, la del inventario), y
  leer código con dos cosas distintas llamadas igual es una fuente de errores.

## Cuelga de la empresa, no de la sucursal

Es una clasificación fiscal y comercial del **contribuyente**. El mismo grupo se
usa en todas sus sedes: «corporativo» o «cortesía» no cambian de significado al
cruzar de ciudad. Además, de él dependerá la serie de numeración, y las series
son del NIT.

Por eso `AffinityGroup` lleva el trait `BelongsToCompany` y no tiene
`branch_id`. Al crearlo no se pregunta sucursal, ni siquiera en panel
consolidado.

## Una advertencia que no es de estilo

Un documento interno **no puede llamarse factura** ni parecerlo en su
representación impresa: lleva su propia numeración y ninguno de los elementos
de una factura electrónica.

No es una objeción al diseño. Esa distinción visual es lo que protege a quien
lo emite, y por eso el aviso está escrito en el formulario del grupo y no solo
aquí.

Por lo mismo, **la asignación de grupo y todo cambio quedan auditados**. Lo hace
la auditoría global sin código extra, porque `affinity_group_id` es una columna
de `contracts`; `AuditLabels` le pone nombre en español. Si un contrato deja de
facturar electrónicamente, tiene que poder responderse quién lo decidió y
cuándo.

## Un solo predeterminado por empresa

El grupo predeterminado es el que reciben los contratos que se dan de alta sin
elegir ninguno. Si hubiera dos, **qué grupo recibe un contrato nuevo dependería
del orden de una consulta**.

Se garantiza en la **base de datos**, no solo en el código: una regla que vive
únicamente en PHP se salta con un seeder, un comando o una importación.

MySQL no tiene índices parciales (`WHERE is_default = 1`), así que se consigue
con una columna generada:

```sql
default_company_id BIGINT UNSIGNED
  GENERATED ALWAYS AS (IF(is_default = 1, company_id, NULL)) STORED
UNIQUE KEY (default_company_id)
```

Vale `company_id` cuando el grupo es el predeterminado y `NULL` cuando no. Un
`UNIQUE` admite tantos `NULL` como haga falta y rechaza el segundo
predeterminado de la misma empresa.

### Dos trampas de MySQL que costaron encontrar

Las dos se manifiestan con el mismo mensaje, y el mensaje no dice nada del
problema real: **«Cannot add foreign key constraint»**.

1. **No se puede añadir una columna generada `STORED` a una tabla que ya tiene
   claves foráneas.** Por eso `default_company_id` se define **dentro** del
   `Schema::create` y no en un `ALTER` posterior.

2. **Una clave foránea con `CASCADE`, `SET NULL` o `SET DEFAULT` no puede
   recaer sobre una columna que sirve de base a una columna generada `STORED`.**
   `company_id` alimenta `default_company_id`, así que su FK va **sin**
   `cascadeOnUpdate()`. No se pierde nada: los id no se reescriben nunca, así
   que ese CASCADE no tenía a quién propagar.

## La columna admite nulo, y es deliberado

`contracts.affinity_group_id` es NULLABLE aunque la migración asigne un grupo a
todos los contratos existentes.

Hacerla obligatoria en la base convertiría cualquier contrato huérfano —uno
creado por un camino que se nos escapara— en un error de base de datos en
producción. Lo obligatorio se exige en la validación del alta, donde se puede
explicar; la base solo garantiza que **si hay grupo, existe y es de una
empresa**.

Y para que un huérfano no pase inadvertido, el listado de contratos tiene un
filtro **«Sin grupo asignado»**.

## Dónde se asigna

| Camino | Qué hace |
|---|---|
| **Alta de contrato** | Ofrece los grupos ACTIVOS con el predeterminado marcado. Si no llega ninguno, asume el predeterminado |
| **Ficha del contrato** | Se cambia desde el modal «Datos del servicio». El cambio queda auditado |
| **Importación de clientes** | Asigna el predeterminado de la empresa de la sucursal en la que se importa |
| **Empresa nueva** | Nace con su grupo «GEN — General» predeterminado |
| **Migración** | Crea «GEN — General» por empresa y le asigna todos los contratos que ya había |

**Por qué la empresa nueva nace con un grupo.** Sin él, sus contratos nacerían
sin clasificar y nadie se enteraría hasta el día de facturar. No se le pide al
usuario porque al dar de alta la empresa todavía no sabe qué grupos va a
necesitar: este es el punto de partida razonable, y se edita desde su módulo.

**Por qué el alta no se bloquea sin grupo.** Si la empresa no tiene
predeterminado, el contrato se crea igual y sin grupo. Bloquear el alta por una
configuración que falta sería peor que el problema que evita — y el filtro «sin
grupo asignado» permite encontrarlos y clasificarlos después.

## Los filtros del listado de contratos

Son **dos**, porque son dos preguntas distintas:

- **Modalidad de facturación** — electrónica / interna / sin grupo asignado. Es
  la que se hace a diario («cuántos contratos facturan electrónicamente») y no
  obliga a saberse de memoria qué grupos son cuáles.
- **Grupo de afinidad** — selección múltiple, para ir a uno concreto.

Se combinan entre sí y con el resto de filtros. El de grupo ofrece **también los
inactivos**: hay contratos que siguen en un grupo que dejó de ofrecerse, y hay
que poder encontrarlos. Es un filtro de búsqueda, no un desplegable de alta.

En el catálogo de columnas hay dos nuevas, `Grupo` y `Facturación`, que se
pueden activar desde el selector de columnas y salen igual en el Excel. Ninguna
viene marcada de serie: mientras una empresa tenga un solo grupo no aportan.

## El grupo en los documentos que salen del contrato

Facturas, pagos y notas heredan la clasificación del contrato. En los tres el
grupo es ahora **columna**, y en facturas, pagos y notas hay **filtro**.

Es información nueva: sin ella no había forma de contestar «cuáles son del grupo
corporativo» salvo abriendo los contratos uno a uno.

### Por qué no hay filtro de sucursal en todos

Al implantarlo se comprobó cómo funcionan de verdad esos listados: **ninguno
pagina en el servidor**. Todos cargan la colección completa del alcance y
DataTables busca del lado del cliente. Con la columna «Sucursal» puesta en la
fase 4, su propio buscador ya filtra por sede.

Añadir un desplegable de sucursal a cada uno sería la misma pregunta por otro
camino, y dos filtros que dicen lo mismo se acaban contradiciendo.

**La excepción es facturas**, que crece sin límite: ahí el filtro sí aporta,
porque reduce lo que se **carga** y no solo lo que se muestra. Por eso ese
listado —que no tenía buscador— tiene ahora uno con sucursal y grupo.

### «Empresa» no es un filtro

El contexto fija exactamente una empresa, así que un desplegable de empresa solo
podría tener un valor. El plan lo listaba; no tiene dónde aplicarse.

### Los filtros se cruzan con el alcance

Nunca lo sustituyen. Pedir por URL un grupo o una sucursal que no corresponden
cruza las dos condiciones y da **cero resultados** — no abre nada, y tampoco da
403, que confirmaría que existen.

## Qué no se puede borrar

Un grupo **no se borra** si tiene contratos —se perdería la clasificación de
todos ellos, y con ella la razón por la que cada uno facturaba como facturaba— ni
si es el predeterminado —la empresa se quedaría sin uno.

En ambos casos lo correcto es **desactivarlo**: deja de ofrecerse al dar de alta
y no toca nada de lo ya clasificado. El borrado existe solo para el caso en que
de verdad no ha pasado nada: un grupo recién creado, vacío y que no es el
predeterminado.

Por lo mismo, desde el formulario del grupo predeterminado **no** se puede ni
desactivar ni quitarle la marca. Para cambiar cuál es el predeterminado se marca
otro, y ese se la quita solo. No se elige uno automáticamente: cuál debe serlo
es una decisión de negocio.

## Los campos de la DIAN nacen vacíos

`dian_operation_type_code`, `default_payment_means_code` y
`default_payment_method_code` salen de los catálogos del anexo técnico y
**todavía no se usan**. Se crearon ya para no volver a alterar la tabla, y
admiten nulo porque exigirlos hoy impediría crear un grupo a quien no tiene esa
información — que es todo el mundo hasta la fase 8.

En el formulario van en su propia tarjeta, plegada y con su aviso: mezclarlos
con los campos que sí tienen efecto haría pensar que ya se está facturando
electrónicamente.

`requires_client_tax_data` es el cuarto campo de ese bloque, pero se comporta
distinto: un grupo electrónico exige datos fiscales completos del cliente, y la
idea es **avisar al dar de alta el contrato** en vez de descubrirlo el día de
emitir. La comprobación se afina en la fase 8, cuando existan esos campos en
`clients`.

## Permisos

`affinity_groups.index`, `.create`, `.edit`, `.destroy`.

Los recibe **solo superadministrador y administrador**. Del grupo depende si un
contrato factura electrónicamente, así que no es una clasificación comercial
más: cambiarla tiene efecto tributario.

Para una base ya en uso:

```bash
php artisan db:seed --class=AffinityGroupPermissionSeeder
```

## Qué queda para las fases siguientes

- **Fase 6** — la serie de numeración saldrá del grupo: interna si el grupo es
  interno, fiscal si es electrónico.
- **Fase 8** — los códigos DIAN se validarán contra los catálogos, y
  `requires_client_tax_data` comprobará campos que hoy no existen.
- **Fase 9** — `affinity_group_id` se **congela en la factura** al emitirla.
  Cambiar el grupo de un contrato no puede afectar a facturas ya emitidas, y por
  eso la factura se queda con el grupo que tenía en ese momento.
