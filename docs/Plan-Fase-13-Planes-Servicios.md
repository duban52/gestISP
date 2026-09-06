# Fase 13 · El plan deja de evaporarse, y se comparte entre sedes

Plan de trabajo para dos problemas que salieron de la auditoría de
grupos de afinidad. Van juntos porque tocan las mismas tablas, pero son
**independientes**: la parte 1 se puede hacer sola y en un rato; la
parte 2 es una migración de datos con decisiones que tomar.

---

# Por qué

## El problema 1: un plan puede desaparecer bajo los pies de un contrato

`contracts.plan_id` está declarado así desde el principio:

```php
$table->foreignId('plan_id')->nullable()->constrained()->onDelete('set null');
```

Borrar un plan deja en null el `plan_id` de **todos** sus contratos. Y
un contrato sin plan no tiene servicios que facturar, porque
`InvoiceGenerator::addItems()` los saca de `$contract->plan->services`.

Lo que pasaba hasta ahora: se emitía la factura igual, con cero
renglones y total cero. En una interna es un documento absurdo. En una
**electrónica** es caro:

- gasta un consecutivo del rango autorizado, que no se recupera;
- produce un XML que el XSD de la DIAN rechaza, porque un `Invoice`
  sin `InvoiceLine` no es válido;
- deja un hueco en la numeración y ningún documento que enseñar.

Ya hay una red debajo —`InvoiceGenerator` se niega a emitir una factura
vacía, y las vistas aguantan un contrato sin plan— pero eso trata el
síntoma. **La causa es que un plan con contratos vivos se puede borrar.**

## El problema 2: cada sede duplica sus planes y sus servicios

Hoy `plans.branch_id` y `services.branch_id` son obligatorios. Un
contrato de la sucursal A no puede usar un plan de la B, ni siquiera
siendo la misma empresa:

```php
// ContractController.php:256
Rule::exists('plans', 'id')->where('branch_id', $branchId),
```

Consecuencias:

- El mismo "Internet 100 Mb" existe una vez por sede, y **sus datos
  fiscales pueden divergir**. Es exactamente el riesgo que ya se
  materializó con la clasificación de IVA: "Servicio de TV" al 19% y
  "Television" al 0% conviviendo.
- Crear una sede nueva obliga a recrear el catálogo entero.
- Un cambio de precio o de UNSPSC hay que hacerlo N veces.

Un servicio lleva UNSPSC, unidad de medida y clasificación de IVA. Eso
**es del contribuyente, no de la sede**. Que se pueda escribir distinto
en cada sucursal es un problema fiscal esperando a ocurrir.

---

# Lo que ya está hecho (no rehacerlo)

Conviene saberlo antes de planificar de más:

| | Estado |
|---|---|
| `plans.company_id` | **Ya existe.** La puso `add_company_to_tenant_tables` |
| `services.company_id` | **Ya existe.** Ídem |
| `BelongsToCompany` en Plan y Service | Ya aplica: nadie ve el catálogo de otra empresa |
| `plans.name` único | Ya es por sucursal (`plans_branch_name_unique`) |
| Factura vacía | Ya se rechaza (`InvoiceGenerator::hayAlgoQueFacturar()`) |
| Vistas con plan nulo | Ya aguantan |

O sea: **media fase 2 ya está en el esquema**. Falta usarla.

---

# Hallazgos que hay que resolver de paso

Salieron mirando esto y no se pueden dejar:

### `ContractRequest` es código muerto que contradice la regla

`app/Http/Requests/ContractRequest.php` valida así:

```php
'plan_id' => 'nullable|exists:plans,id',
```

Sin `branch_id`. **Ningún controlador lo usa** —se comprobó—, pero está
ahí, y el día que alguien lo conecte creyendo que es el sitio correcto,
la regla de sucursal desaparece sin que nadie lo note.

Se borra, o se alinea con `ContractController`. Borrarlo es mejor: dos
sitios que validan lo mismo acaban diciendo cosas distintas.

### Hay contratos con plan de otra sucursal

En la base de desarrollo salen **4**. Probablemente datos de prueba
—`Contract::factory()` asigna `plan_id` al azar— pero hay que
comprobarlo en producción antes de migrar:

```sql
SELECT ct.id, ct.branch_id AS sede_contrato, p.branch_id AS sede_plan, p.name
FROM contracts ct JOIN plans p ON p.id = ct.plan_id
WHERE p.branch_id <> ct.branch_id;
```

Si en producción salen filas, la parte 2 las **arregla sola** (el plan
pasa a ser de la empresa y deja de ser una inconsistencia). Si se hace
solo la parte 1, hay que decidir qué hacer con ellas.

---

# Parte 1 · El plan no se borra si tiene contratos — HECHA

Desplegable sola. Terminada el 2026-09-05.

## Qué se cambia

1. **Migración**: `contracts.plan_id` pasa de `SET NULL` a
   `restrictOnDelete()`.

   ```php
   $table->dropForeign(['plan_id']);
   $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
   ```

   Ojo: hay que dejar la columna **nullable** igual. Un contrato puede
   nacer sin plan (el formulario lo permite); lo que no puede es
   perderlo por detrás.

2. **`PlanController::destroy()`**: se niega con mensaje si el plan
   tiene contratos, en vez de dejar que reviente la restricción.
   El mismo patrón que ya usa el almacén con las categorías
   («no se borra una categoria con materiales»).

3. **Desactivar en vez de borrar**: si `plans` no tiene columna
   `active`, añadirla. Un plan que ya no se vende pero tiene contratos
   vivos necesita poder retirarse del formulario sin desaparecer.

4. **Borrar `ContractRequest`**.

## Qué hacer con los contratos que YA quedaron sin plan

La migración no los arregla: `plan_id` ya está en null y no hay forma
de saber cuál era. Se reportan y se asignan a mano.

```sql
SELECT id, numero_visible, branch_id, status FROM contracts WHERE plan_id IS NULL;
```

En desarrollo hay 1.

## Pruebas

- No se puede borrar un plan con contratos.
- Sí se puede desactivar, y entonces no aparece en el formulario.
- Un contrato existente conserva su plan aunque esté desactivado.
- Borrar un plan sin contratos sigue funcionando.

## Riesgo

Bajo. La migración solo cambia una clave foránea y **falla en el acto**
si hay contratos apuntando a planes inexistentes — cosa que no puede
pasar, porque la FK ya existe.

---

# Parte 2 · Compartir entre sucursales

Aquí está el trabajo de verdad.

## La decisión de diseño

D8 del plan maestro decía: *«Servicio → empresa (lleva UNSPSC e IVA: es
fiscal). Plan → sucursal, con el precio de la empresa como valor por
defecto»*.

**Propongo cambiarla ligeramente**, y conviene decidirlo antes de tocar
nada:

| | D8 original | Propuesta |
|---|---|---|
| Servicio | De la empresa | De la empresa |
| Plan | De la sucursal | De la empresa, **opcionalmente** restringido a una sede |

El mecanismo es `branch_id` **nullable**, con el mismo significado que
ya tiene en `numbering_ranges`:

- `branch_id = null` → es de la EMPRESA, disponible en todas sus sedes.
- `branch_id = 7` → exclusivo de esa sede.

Por qué es mejor que D8 tal cual: un ISP con varias sedes normalmente
vende **el mismo** catálogo, y la excepción es el plan local. Con D8
el caso común es el que duplica. Además el patrón ya existe en el
sistema, así que no hay que inventar semántica nueva.

> **Esto hay que confirmarlo antes de empezar.** Es la única decisión de
> la fase que no se puede cambiar después sin otra migración.

## Los pasos

### 2.1 · Esquema

- `services.branch_id` → nullable.
- `plans.branch_id` → nullable.
- Reemplazar `plans_branch_name_unique` por un índice que funcione con
  nulos. MySQL permite varios nulos en un índice único, así que
  `UNIQUE(company_id, branch_id, name)` **no** impide dos planes de
  empresa con el mismo nombre. Hay dos salidas:
  - columna generada `branch_key = COALESCE(branch_id, 0)` y único
    sobre `(company_id, branch_key, name)`; o
  - validar la unicidad en la aplicación.

  La primera es la que aguanta. **Decidir cuál.**

### 2.2 · Consolidar los duplicados

Un comando `gestisp:catalogo-consolidar`, con `--dry-run` como los
demás:

1. Agrupa servicios por `(company_id, name)`.
2. Para cada grupo con más de uno, **informa** las diferencias de
   precio, tarifa y clasificación fiscal. Si difieren, **no toca nada**
   y lo marca para decisión humana: fusionar dos servicios con IVA
   distinto es una decisión fiscal.
3. Si son idénticos, deja uno con `branch_id = null` y repunta el
   pivote `plan_service` al superviviente.
4. Lo mismo con los planes.

> Lo que **no** debe hacer nunca: tocar `invoice_items`. Los renglones
> de una factura emitida son copias congeladas y no apuntan al servicio.
> Consolidar el catálogo no puede alterar nada ya facturado — y hay que
> comprobarlo explícitamente en las pruebas.

### 2.3 · Validación y formularios

- `ContractController:256` pasa a aceptar el plan si
  `branch_id IS NULL OR branch_id = <la del contrato>`.
- El selector de planes del formulario de contrato ofrece los de la
  empresa más los de su sede.
- `PlanController` y `ServiceController`: al crear, elegir si es de la
  empresa o de una sede. Por defecto, **de la empresa**.
- El importador (`ClientContractImporter::resolverPlan()`) busca
  primero en la sede y luego en la empresa.

### 2.4 · Precio por sede — DECIDIDO

**El precio es igual en todas las sedes** (decisión del usuario,
2026-09-05).

Nada que hacer: el precio vive en el servicio y punto. No hace falta
tabla de excepciones ni tocar `InvoiceGenerator::addItems()`, que es lo
que más habría complicado la parte 2.

Esto además refuerza el argumento de subir el servicio a la empresa: si
el precio no depende de la sede, tener el mismo servicio repetido por
sede solo puede producir divergencias, nunca aportar nada.

## Pruebas de la parte 2

Lo que hay que defender, más allá del CRUD:

- Un contrato puede usar un plan de la empresa.
- Un contrato **no** puede usar un plan de otra EMPRESA (el alcance ya
  lo impide; hay que fijarlo).
- Un contrato no puede usar un plan exclusivo de otra sede.
- Consolidar no altera ninguna factura ya emitida.
- Consolidar **no fusiona** servicios con clasificación fiscal distinta.
- Dos empresas pueden tener cada una su plan "100 Megas".

## Riesgo

**Medio-alto**, y está concentrado en 2.2. Un servicio fusionado mal
cambia lo que se le factura a un cliente el mes siguiente. Por eso el
comando informa y se niega ante cualquier diferencia, en vez de
resolverla por su cuenta.

---

# Orden y despliegue

1. **Parte 1 completa**, desplegada y verificada. Es independiente y
   quita el problema que ya mordió.
2. Decidir las tres cosas abiertas (abajo).
3. **Parte 2**, con ensayo sobre copia de producción — como en
   [Despliegue.md](Despliegue.md), paso 1.

No hacer las dos en el mismo despliegue. La parte 1 es reversible; la
parte 2 mueve datos.

---

# Lo que hay que decidir antes de empezar

| # | Pregunta | Estado |
|---|---|---|
| 1 | ¿Plan de empresa con excepción por sede, o plan siempre de sede? | **Abierta.** Cambia el esquema y no se revierte sin otra migración |
| 2 | ¿El mismo plan puede costar distinto en dos sedes? | **Decidido: no.** El precio es igual en todas las sedes |
| 3 | ¿Unicidad del nombre por columna generada o por validación? | **Abierta.** La primera es más sólida; la segunda, más simple |

Y una que es de datos, no de diseño: **qué hacer con los contratos que
ya quedaron sin plan**. Hay que asignárselo a mano; el sistema no puede
adivinar cuál era.

---

# Referencias

| Pieza | Archivo |
|---|---|
| Decisión D8 | `docs/Plan-Empresa-Sucursales-Grupos.md` (sección J) |
| Dónde se factura el plan | `app/Billing/Services/InvoiceGenerator.php::addItems()` |
| La regla de sucursal | `app/Http/Controllers/ContractController.php:256` |
| El importador | `app/Services/Import/ClientContractImporter.php::resolverPlan()` |
| Validación muerta | `app/Http/Requests/ContractRequest.php` |
| Patrón de `branch_id` nullable | `numbering_ranges`, y `InvoiceNumerator::lockAuthorizedRange()` |
