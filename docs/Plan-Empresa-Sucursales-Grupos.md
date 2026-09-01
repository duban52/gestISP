# Empresa, Sucursales y Grupos de Contrato
## Auditoría del proyecto y plan de implementación

> **Estado: plan. No se ha modificado nada.**
> Ningún archivo de código, migración, dependencia ni configuración fue tocado
> para producir este documento.

**Convención:** ✅ confirmado leyendo el código · 🔎 inferido · ⏳ pendiente de
validar · ⚖️ decisión que hay que tomar antes de programar.

---

# A. Arquitectura actual

## A.1 La pila

| | |
|---|---|
| Laravel `^10.10` · PHP `^8.1` (corriendo 8.3) · MySQL · 90 migraciones |
| Blade + AdminLTE 3.14 (Bootstrap 4) · DataTables cliente · sin SPA |
| `laravel/ui` (sesión web) · Sanctum instalado **sin uso externo** |
| `spatie/laravel-permission` 6.13 · **137 permisos** · 3 roles |
| `QUEUE_CONNECTION=database` · 2 jobs · `FILESYSTEM_DISK=local` |
| Auditoría propia: tabla `audits` polimórfica + `AuditLogger` + middleware |
| Capa de dominio real en `app/Billing/` y `app/Services/` |

## A.2 El eje organizativo hoy: `branches`

✅ **No existe ninguna entidad Empresa.** Ni tabla, ni modelo, ni migración en
las 90 revisadas. `branches` es la raíz de todo, y **lleva su propio NIT**:

```
branches
├── id · name (UNIQUE global ⚠) · nit ⚠
├── country · department · municipality · address   (texto libre)
├── contract_prefix · contract_next_number          ← numeración de contratos
├── moving_price · reconnection_price
└── message_custom_invoice · observation
```

En el modelo de datos de hoy, **sucursal = contribuyente**.

## A.3 Usuarios y acceso

✅ `user_branch` es `user_id · branch_id · role_id`. Un usuario puede pertenecer
a **varias sucursales con un rol distinto en cada una**. Esa parte del diseño ya
es correcta y se conserva; lo que falta es el nivel de empresa por encima.

El contexto vive en la sesión como **dos escalares**: `branch_id` y
`current_role_id`. `EnsureBranchSession` los repone desde
`users.selected_branch_id` cuando la sesión los pierde.

## A.4 El login actual

✅ El flujo de hoy es el contrario del que pides:

```
1. El formulario pide email
2. JS llama a GET /user/branches?email=...     ← SIN AUTENTICAR
3. Se rellena un <select> de sucursales
4. El usuario manda email + contraseña + branch_id
5. authenticated() valida que tenga acceso a esa sucursal
```

**Esto es una vulnerabilidad de enumeración.** ✅ Confirmado: la ruta está en
`routes/web.php:159`, fuera de cualquier grupo con middleware. Cualquiera puede
preguntar por un correo y saber (a) si existe un usuario con él y (b) a qué
sucursales pertenece. El prelogin que pides lo cierra de paso, y por sí solo ya
justifica el cambio.

✅ **No existe cambio de sucursal en caliente.** Para cambiar de sucursal hay que
cerrar sesión y volver a entrar.

## A.5 Aislamiento: el punto débil

| Mecanismo | Cobertura |
|---|---|
| `where('branch_id', session('branch_id'))` a mano | **113 apariciones en 31 de 43 controladores** |
| `scopeDeSucursal()` en el modelo | **6 modelos**: `Category`, `FiberCable`, `Material`, `NapBox`, `OpticalNetwork`, `SpliceClosure` |
| Global scope de Eloquent | **ninguno** |
| Middleware que lo fuerce | **ninguno** |

El aislamiento depende hoy de que el programador se acuerde. Con una empresa es
un bug; **con dos es una fuga entre contribuyentes**.

⚠️ Además, el proyecto **no define `Gate::before`**, así que `@can` en las vistas
evalúa los roles **globales** del usuario y no el rol de la sucursal activa. Hoy
se compensa exigiendo el permiso en el controlador.

## A.6 Qué está y qué no está acotado por sucursal

✅ Con `branch_id` (32 modelos): `Client`, `Contract`, `Invoice`,
`CreditDebitNote`, `Payment*`, `BillingRun`, `CashRegister`, `Plan`, `Service`,
`Material`, `Warehouse`, `Olt`, `Ont`, `PppoeAccount`, `Router`,
`OpticalNetwork`, `NapBox`, `TechnicalOrder`, `User`, `Audit`, `UserSession`…

✅ Sin `branch_id` (27 modelos) — **heredan contexto por su padre**:
`InvoiceItem`, `Payment`, `AditionalCharge`, `ContractComment`, `NapPort`,
`PonPort`, `Splice`, `Splitter`, `CableStrand`, `OntMetric`, `OltBoard`,
`Inventory`, `MaterialMovement`, `TechnicalOrderMaterial`, `VlanOlt`,
`LineProfile`, `SrvProfile`, `NetworkZone`…

Eso es **correcto** y hay que conservarlo: `InvoiceItem` no necesita
`branch_id`, lo hereda de su factura. Añadirlo sería denormalizar y crear una
segunda verdad que puede divergir.

## A.7 Numeración: hoy hay TRES mecanismos distintos

| Documento | Dónde vive el contador | Bloqueo | ¿Resolución DIAN? |
|---|---|---|---|
| **Contrato** | `branches.contract_prefix` + `contract_next_number` — **en la propia fila de la sucursal** | `Branch::lockForUpdate()` | — |
| **Factura** | tabla `invoice_numbering_sequences` | `lockForUpdate()` | ✅ **ya tiene** `resolution_number`, `valid_from/until`, `range_start/end` |
| **Nota C/D** | tabla `note_numbering_sequences` | 🔎 por revisar | ❌ no tiene |

Los tres usan bloqueo pesimista y funcionan. `contracts.contract_number` es
**UNIQUE global** ✅ y `invoices.full_number` también ✅.

**La secuencia de facturas ya anticipó la DIAN.** El comentario de su migración
dice: *«cuando llegue la habilitación DIAN solo se registra la resolución en la
secuencia, sin cambiar código»*.

## A.8 Facturación

✅ `app/Billing/` con Enums, Events y Services separados. `InvoiceGenerator`
documenta que se eliminó la absorción de saldos por ser *«incompatible con
facturación electrónica DIAN»*. Existe el evento `InvoiceIssued`, descrito como
*«punto de enganche de la facturación electrónica»*, hoy con un solo listener.

`invoices` ya tiene `type`, `prefix`, `number`, `full_number` (UNIQUE),
`numbering_sequence_id`, `voided_at/by/reason`, `subtotal`, `discount`, `tax`,
`total`, `status`, `period_start/end`.

## A.9 Bloqueos duros para multiempresa

✅ Dos restricciones **impiden físicamente** que convivan dos empresas:

```
branches.name  →  ->unique()    dos empresas no pueden tener una "Sucursal Principal"
plans.name     →  ->unique()    ni cada una su plan "100 Megas"
```

---

# B. Arquitectura propuesta

```
Company  (el contribuyente — un NIT)
│
├── Identidad fiscal            NIT+DV · razón social · organización jurídica
│                               responsabilidades · dirección + DANE
├── Modo de operación           independiente | consolidado        ⚖️ D1
├── Configuración DIAN          ambiente · SoftwareID · PIN · habilitación
├── Certificados (1:N)          .p12 cifrado · vigencia · histórico
├── Resoluciones (1:N)          número · vigencia · tipo de documento
│    └── Rangos (1:N)           prefijo · desde · hasta · consecutivo → Branch
├── Grupos de contrato (1:N)    código · nombre · ¿electrónica? · defaults
│
└── Branches (1:N)
     ├── Datos operativos       dirección · contacto · precios
     ├── Secuencias internas    contrato · documento interno
     ├── Reglas de facturación  prorrateo · plazo · corte     (ya existe)
     └── Usuarios (N:M + rol)                                  (ya existe)

Contract → company_id (derivado) · branch_id · contract_group_id
              └── el grupo decide si su factura va a la DIAN
```

## B.1 El cambio central: el contexto deja de ser un escalar

Hoy la sesión guarda `branch_id`, un entero. Para soportar la Modalidad B eso ya
no basta. **El contexto pasa a ser un objeto explícito**:

```php
final class OperatingContext
{
    public int $companyId;          // SIEMPRE presente
    public ?int $branchId;          // null = panel consolidado
    public array $branchIds;        // sucursales que el usuario ve AQUÍ
    public int $roleId;             // rol en este contexto
    public bool $consolidado;
}
```

Reglas:

- **Modalidad A** — `branchId` tiene valor, `branchIds = [branchId]`. Es el
  comportamiento de hoy.
- **Modalidad B** — `branchId = null`, `branchIds` = todas las sucursales de la
  empresa a las que el usuario tenga acceso. Al **crear** cualquier documento
  hay que elegir sucursal, y esa elección se valida contra `branchIds`.

`branchIds` es la pieza que hace que el escenario D siga funcionando: en una
empresa consolidada, el gerente ve las cinco sucursales y el cajero de Bogotá
solo la suya, **con la misma arquitectura**.

## B.2 Aislamiento: de filtro manual a global scope

```php
trait BelongsToCompany   // company_id = contexto. SIEMPRE. Sin excepción.
trait BelongsToBranch    // branch_id IN contexto->branchIds
```

Los 113 `where('branch_id', session('branch_id'))` actuales se vuelven
redundantes pero **no se quitan en la misma fase**: primero se añade el scope,
se comprueba que todo sigue pasando, y solo después se limpian. Quitarlos antes
deja una ventana sin ninguna barrera.

## B.3 Grupo de contrato

**Nombre propuesto: `ContractGroup` / «Grupo de contrato».**

Descarté las alternativas: «Tipo de facturación» acopla el nombre a un solo uso
—y tú mismo dices que quieres poder ampliar reglas—; «Categoría» ya está usado
en el proyecto (`Category`, del inventario) y crearía confusión al leer código.

Pertenece a la **empresa**, no a la sucursal: es una clasificación fiscal y
comercial del contribuyente, y un mismo grupo tiene que poder usarse en varias
sucursales.

---

# C. Cambios de base de datos

## C.1 Tablas nuevas

### `companies`
```
id · legal_name · trade_name
document_type_code · document_number · verification_digit
organization_type_code · tax_regime_code
address · department_dane_code · municipality_dane_code · postal_code
email · phone · logo
operation_mode ENUM('independent','consolidated')   ← ⚖️ D1
electronic_invoicing_enabled BOOLEAN DEFAULT false   ← interruptor maestro
active · timestamps
```
**Índices:** UNIQUE `(document_type_code, document_number)`.

### `company_tax_responsibilities`
`company_id · responsibility_code` — N:M. Son varias por empresa (`O-13`,
`O-15`, `O-23`…); una columna no basta.

### `contract_groups`
```
id · company_id · code · name · description
requires_electronic_invoicing BOOLEAN DEFAULT false
active BOOLEAN DEFAULT true
is_default BOOLEAN DEFAULT false        ← el que reciben los contratos sin grupo
sort_order
-- Campos que propongo añadir y explico en §H.4:
dian_operation_type_code       ← tipo de operación DIAN del documento
default_payment_means_code     ← contado / crédito
default_payment_method_code    ← efectivo, transferencia…
requires_client_tax_data BOOLEAN  ← si exige datos fiscales completos del cliente
notes
timestamps
```
**Índices:** UNIQUE `(company_id, code)` · índice `(company_id, active)` ·
UNIQUE parcial de un solo `is_default` por empresa.

### `document_sequences` — numeración **interna**
```
id · company_id · branch_id (nullable)
document_type   ('contract','internal_invoice','internal_note',…)
prefix · padding · current_number
active · timestamps
```
**Índices:** UNIQUE `(company_id, branch_id, document_type, active)` ·
UNIQUE `(company_id, prefix, document_type)`.

### `dian_resolutions` + `numbering_ranges` — numeración **fiscal**
```
dian_resolutions:  company_id · resolution_number · document_type_code
                   valid_from · valid_until · technical_key_encrypted · active
numbering_ranges:  resolution_id · branch_id (nullable)
                   prefix · range_start · range_end · current_number · active
```
**Índice crítico:** UNIQUE `(company_id, prefix)` entre rangos activos. Es lo
que impide que dos sucursales del mismo NIT usen el mismo prefijo y produzcan
consecutivos duplicados ante la DIAN aunque la base los vea distintos.

### `dian_configurations` (1:1 con `companies`) y `dian_certificates` (1:N)
Detallados en §H.

### `electronic_documents`, `document_transmissions`, `dian_responses`
Detallados en §H.3.

### Catálogos DIAN
~12 tablas sembradas desde los `.gc` y XLSX de `ayuda facturacion dian`.
**Tablas, no enums en PHP**: cambian por resolución y no debe hacer falta
desplegar código para actualizarlos.

## C.2 Cambios en tablas existentes — con su impacto

| Tabla | Cambio | Impacto | Reversible |
|---|---|---|---|
| `branches` | `+ company_id` (NOT NULL tras migrar) | Ninguno funcional | Sí |
| `branches` | **quitar `nit`** → `companies.document_number` | 🔎 revisar `PdfBranding` y plantillas de correo que lo impriman | ⚠️ Migrar el dato primero |
| `branches` | `name` UNIQUE → UNIQUE `(company_id, name)` | Desbloquea multiempresa | Sí |
| `plans` | `+ company_id` · `name` UNIQUE → `(company_id, name)` | Ídem | Sí |
| `services` | `+ company_id` · `+ unspsc_code` · `+ tax_code` | Datos fiscales del catálogo | Sí |
| `clients` | `+ company_id` · `+ document_type_code` · `+ verification_digit` · `+ organization_type_code` · `+ address` · `+ dane codes` · `+ tax_responsibilities` | `type_document` hoy es **texto libre** (3 valores) → migrar a código | Sí |
| `contracts` | `+ company_id` · **`+ contract_group_id`** | El corazón de §5 y §6 | Sí |
| `invoices` | `+ company_id` · `+ contract_group_id` (congelado) · `+ payment_means_code` · `+ payment_method_code` | Ver ⚖️ D5 | Sí |
| `credit_debit_notes` | `+ company_id` | | Sí |
| `payments`, `cash_registers`, `technical_orders`, `olts`, `onts`, `pppoe_accounts`, `routers`, `optical_networks`, `materials`, `warehouses`, `categories` | `+ company_id` | Aislamiento | Sí |
| **`audits`** | `+ company_id` | **Sin esto la propia trazabilidad es un punto de fuga** | Sí |
| `users` | `+ company_id` si se cierra ⚖️ D3 | | Sí |
| `user_branch` | 🔎 evaluar `+ company_id` redundante para índices | | Sí |
| `invoice_numbering_sequences` | **Migrar a `numbering_ranges`** | Datos vivos: consecutivos en uso | ⚠️ **Irreversible en la práctica** |
| `note_numbering_sequences` | **Migrar a `document_sequences`** o a `numbering_ranges` según ⚖️ D6 | Ídem | ⚠️ |
| `branches.contract_prefix/next_number` | **Migrar a `document_sequences`** | Ídem | ⚠️ |

## C.3 Migraciones que marco como delicadas

Tres tocan **contadores en uso**. Si se ejecutan mal, se repiten números de
documentos ya emitidos:

1. `branches.contract_next_number` → `document_sequences`
2. `invoice_numbering_sequences` → `numbering_ranges`
3. `note_numbering_sequences` → destino según ⚖️ D6

**Propuesta:** las tres van en su propia fase, con comando de migración
**idempotente**, ejecutable en seco (`--dry-run`) y con verificación previa y
posterior (contar documentos por prefijo antes y después, y comprobar que el
contador nuevo ≥ el mayor consecutivo ya emitido). Ver §G.

---

# D. Cambios de backend

## D.1 Contexto y autorización

| Pieza | Qué hace |
|---|---|
| `App\Context\OperatingContext` | Objeto de contexto (§B.1), inmutable, resuelto una vez por petición |
| `App\Context\ContextResolver` | Lo construye desde la sesión y lo valida contra la base |
| `EnsureOperatingContext` (middleware) | Sustituye a `EnsureBranchSession`. Repone o exige contexto; **redirige al selector** si falta |
| `BelongsToCompany` / `BelongsToBranch` (traits) | Global scopes |
| `Gate::before` | **Cierra el agujero de `@can`**: resuelve permisos por el rol del contexto, no por los roles globales |
| `AuthorizesBranchInput` | Regla de validación reutilizable: todo `branch_id` que llegue por formulario se comprueba contra `$contexto->branchIds` |

## D.2 Autenticación (prelogin)

- `LoginController::authenticated()` → **deja de exigir `branch_id`**.
- Nuevo `ContextSelectionController`: `GET /contexto` (pantalla) y
  `POST /contexto` (guardar).
- **Se elimina `GET /user/branches`** — la ruta pública que hoy filtra
  pertenencia por correo (§A.4). Su desaparición es parte del entregable, no un
  efecto colateral.
- Nuevo `POST /contexto/cambiar` — cambio de contexto en caliente, sin cerrar
  sesión. Hoy no existe.

**Resolución automática:** si el usuario tiene un solo contexto posible, se
entra directo sin pantalla intermedia. «Un solo contexto» significa: una empresa
**y** (una sucursal **o** la empresa es consolidada).

## D.3 Numeración

| Servicio | Estado |
|---|---|
| `DocumentNumberService` | **Nuevo.** Unifica el bloqueo pesimista y la comprobación de rango. Un solo sitio, una sola suite de pruebas |
| `ContractNumberGenerator` | **Se conserva la API pública**, se le cambia el motor por debajo. Su método `registrarNumeroExterno()` es imprescindible para la importación y no se toca |
| `InvoiceNumerator` | Ídem. Ya hace lo correcto; pasa a consultar `numbering_ranges` |
| `FiscalNumberResolver` | **Nuevo.** Decide de qué serie sale el número de una factura, según ⚖️ D5 |

## D.4 Facturación

| Servicio | Cambio |
|---|---|
| `InvoiceGenerator` | Copia `contract_group_id` a la factura al emitirla (congelado) |
| `BillingRun` / `MonthlyBillingRun` | Acepta contexto de empresa; en consolidado puede correr todas las sucursales |
| **`ElectronicInvoicingDecider`** | **Nuevo y central.** Única fuente de la decisión «¿esta factura va a la DIAN?». Ningún otro sitio la toma |
| Listener de `InvoiceIssued` | Consulta al decider y encola o no |

**La regla, en un solo sitio:**
```php
$empresa->electronic_invoicing_enabled
    && $factura->contractGroup?->requires_electronic_invoicing
    && $empresa->dianConfiguration?->estaEnProduccion()
```
Tres condiciones. Ninguna hardcodeada. Si mañana hay una cuarta, se añade aquí.

## D.5 Consultas y filtros

`ContractQuery` ya recibe `?int $branchId` y cae a `session('branch_id')`.
Pasa a recibir el **contexto** y a filtrar por `branchIds`. Se añaden filtros
`company_id`, `branch_id`, `contract_group_id` y
`requires_electronic_invoicing`.

**El filtro nunca amplía el alcance**: se intersecta con el contexto, no lo
sustituye. Un `branch_id` en la URL que no esté en `branchIds` no da error 403
sino **cero resultados con aviso** — así no se puede sondear qué sucursales
existen.

---

# E. Cambios de frontend

| Pantalla | Estado |
|---|---|
| Login | **Se le quita el selector de sucursal.** Solo correo y contraseña |
| **Selector de contexto** | **Nueva.** Tarjetas de empresa → sucursal. Se salta si solo hay una opción |
| Cambio de contexto | **Nuevo.** En el menú superior, junto al rol y la sucursal actuales |
| Barra superior | Muestra empresa + sucursal (o «Todas las sucursales» en consolidado) |
| Admin. de empresas | **Nueva.** Solo superadministrador de plataforma |
| Admin. de sucursales | Existe; se le cuelga de la empresa y se le quita el NIT |
| **Admin. de grupos de contrato** | **Nueva.** Configuración → Grupos de contratos |
| Alta/edición de contrato | `+ selector de grupo` (obligatorio) · `+ selector de sucursal` **solo en consolidado** |
| Listado de contratos | `+ columna y filtro de grupo` · `+ filtro de sucursal` en consolidado · `+ filtro «va a la DIAN»` |
| Facturación | Distintivo visual claro entre documento fiscal y documento interno |
| Ficha de cliente | `+ campos fiscales` (§C.2) |

⚠️ **El indicador de contexto no es cosmético.** En modo consolidado el usuario
puede crear un contrato en la sucursal equivocada; que la sucursal elegida esté
siempre visible al crear es lo que lo evita.

---

# F. Seguridad

## F.1 Las cuatro capas

```
1. Global scope por company_id      ← barrera principal, no se puede olvidar
2. Global scope por branch_ids      ← alcance dentro de la empresa
3. Validación de todo branch_id de entrada contra el contexto
4. Restricciones UNIQUE de base de datos donde el dato lo permita
```

La capa 1 es la que importa. Las otras tres son defensa en profundidad.

## F.2 Los caminos que se saltan un scope y hay que cubrir a mano

🔎 Esto es lo que más se olvida en los proyectos multitenant:

| Camino | Riesgo | Mitigación |
|---|---|---|
| **Jobs en cola** | El job corre sin sesión: el scope no tiene contexto | El job recibe `company_id` **explícito** en su constructor. Nunca lo lee de la sesión |
| **Comandos de consola** | Ídem | Contexto explícito por argumento |
| **Caché** | Una clave sin empresa sirve datos de otra | Toda clave lleva `company_id` |
| **Archivos** | Rutas compartidas | `storage/app/{company_id}/…`, aislamiento visible en el sistema de ficheros |
| **`findOrFail($id)` con id de la URL** | Salta el scope si el modelo no lo tiene | Route model binding con scope, y traits en **todos** los modelos con `company_id` |
| **Auditoría** | Sin `company_id`, la trazabilidad enseña otras empresas | `+ company_id` en `audits` |
| **Relaciones** | `$factura->contract->client` puede cruzar si el padre no está acotado | Los scopes en todos los eslabones |
| **`@can` sin `Gate::before`** | Evalúa roles globales | Se cierra en la fase 2 |

## F.3 Pruebas de aislamiento como requisito, no como extra

Una prueba **por cada modelo con `company_id`** que intente leer y escribir
datos de otra empresa y compruebe que no puede. Es el tipo de regresión que en
escritorio no se nota y solo aparece cuando ya es tarde.

---

# G. Migración

## G.1 El punto de partida real

✅ Confirmado contigo: hoy **todas las sucursales operan bajo un mismo NIT**. Eso
hace la migración inicial mucho más simple de lo que sería en el caso general.

## G.2 Secuencia

```
1. Crear companies desde los NIT distintos que haya en branches
   (hoy: 1 empresa)
2. branches.company_id ← la empresa de su NIT
3. Propagar company_id por herencia:
      clients, contracts, invoices, payments, technical_orders,
      olts, onts, pppoe_accounts, routers, materials, warehouses,
      cash_registers, plans, services, audits…
   Todas vía su branch_id. Ninguna requiere decisión humana.
4. Crear el grupo por defecto de cada empresa (is_default = true)
5. contracts.contract_group_id ← el grupo por defecto
6. Migrar los tres contadores  ← LA PARTE DELICADA
7. Convertir clients.type_document de texto a código
8. Quitar branches.nit  (solo cuando 1–7 estén verificados)
```

**Los pasos 1–5 no pierden nada y son reversibles.** El 6 y el 8 no.

## G.3 Los contadores

Para cada secuencia migrada:

```
antes:  contador actual + mayor consecutivo realmente emitido
migrar: crear la fila nueva con current_number = MAX(los dos)
después: verificar que ningún documento existente supere el contador
```

El `max()` no es paranoia: `ContractNumberGenerator::reservar()` ya hace
exactamente eso hoy —`max($siguiente, $this->mayorConsecutivoUsado(...))`—
porque el prefijo se puede cambiar y hay contratos importados. La migración
tiene que preservar esa garantía.

**Comando con `--dry-run` obligatorio**, que imprime el antes y el después sin
escribir. Ejecutar en producción solo tras revisar esa salida.

## G.4 Compatibilidad durante la transición

- Las columnas `company_id` nacen **nullable**, se rellenan, y solo después
  pasan a `NOT NULL`. Así la migración no puede fallar a mitad y dejar la tabla
  inutilizable.
- Los 113 filtros manuales **se conservan** mientras se añaden los scopes. Dos
  barreras en paralelo durante una fase; la manual se retira después.
- `branches.nit` se conserva hasta el final como red de seguridad.

---

# H. Facturación electrónica DIAN

Basado en el paquete oficial que ya está en el repositorio:
**Anexo Técnico de Factura Electrónica de Venta v1.9 (Resolución 000165 de
2023)**, con sus XSD, 40 listas de códigos `.gc`, Schematron y ~40 XML de
ejemplo. ✅ Verificado leyendo esos archivos, no de memoria.

## H.1 Separación de datos, que es lo que pides en §7

```
DATOS DE CUALQUIER FACTURA          →  tabla invoices  (ya existen casi todos)
   número, fechas, cliente, líneas, impuestos, totales, estado comercial

DATOS DE FACTURA ELECTRÓNICA        →  tabla electronic_documents  (nueva)
   CUFE, XML firmado, QR, resolución usada, certificado usado,
   ambiente, estado DIAN, transmisiones, respuestas
```

**Una factura interna no toca `electronic_documents`.** Ni una columna. Eso es
exactamente lo que evita «contaminar» las facturas que no se reportan.

## H.2 Lo que falta para poder emitir (✅ verificado contra el anexo)

| Falta | Dónde | Evidencia |
|---|---|---|
| Código de tipo de documento del cliente | `clients` | `TipoIdFiscal.gc`: `13` cédula, `31` NIT, `22` c. extranjería… Hoy es texto libre |
| Dígito de verificación | `clients`, `companies` | No existe en ninguna parte |
| Tipo de organización jurídica | `clients`, `companies` | `AdditionalAccountID` es obligatorio en UBL |
| Responsabilidades fiscales | `companies`, `clients` | `TipoResponsabilidad.gc` |
| **Códigos DANE** | catálogo | ✅ `ColombiaLocations` trae 32 departamentos y 1.104 municipios **solo con nombre**. Descomprimido y verificado |
| Dirección fiscal del cliente | `clients` | Hoy la dirección está en el contrato, y es la del **servicio** — son dos cosas distintas |
| Código de producto (UNSPSC) | `services` | `13.3.5 Productos` |
| Forma y medio de pago | `invoices` | `FormasPago.gc`, `MediosPago.gc` |
| Resolución en las notas | `note_numbering_sequences` | Las de factura sí la tienen; las de nota no |

## H.3 El bloque que hay que construir

✅ Del XSD `DIAN_UBL_Structures.xsd`, toda factura lleva:

```
sts:DianExtensions
├── InvoiceControl        ← resolución, vigencia, prefijo, rango autorizado
├── InvoiceSource         ← CO
├── SoftwareProvider      ← NIT del prestador + SoftwareID (UUID)
├── SoftwareSecurityCode  ← SHA-384
├── AuthorizationProvider ← NIT de la DIAN (800197268)
└── QRCode
```

Y el CUFE es **SHA-384** (`AlgoritmoCUFE.gc`: `CUFE-SHA384`).

Ambientes ✅ (`TipoAmbiente.gc`): `1` = Producción, `2` = Pruebas.
Endpoints vistos en los XML de ejemplo: `catalogo-vpfe-hab.dian.gov.co`
(habilitación) y `catalogo-vpfe.dian.gov.co` (producción).

⏳ **Pendiente:** el paquete **no trae ningún WSDL** y no logré extraer el texto
de `Guia-Herramienta-para-el-Consumo-de-Web-Services.pdf`. Los endpoints
exactos, nombres de operación y mecanismo de autenticación siguen sin confirmar.

⚠️ **Restricción técnica confirmada:** ✅ `php -m` **no incluye `soap`**. Y el
validador Schematron compilado del paquete es **XSLT 3.0 con extensiones
Saxon**, que libxslt (lo que trae PHP) no ejecuta. La validación **XSD** sí es
viable con `ext-dom`, que está disponible.

## H.4 Campos del grupo que propongo añadir, y por qué

Pediste que analizara qué más debería llevar el grupo. Estos cuatro salen de la
estructura del anexo, no de imaginación:

| Campo | Para qué |
|---|---|
| `dian_operation_type_code` | El tipo de operación va en el XML (`TipoOperacionF.gc`). Un grupo «corporativo» y uno «estándar» pueden diferir |
| `default_payment_means_code` | Contado vs crédito. Determina si hay que informar fecha de vencimiento |
| `default_payment_method_code` | Efectivo, transferencia… |
| `requires_client_tax_data` | Un grupo electrónico exige datos fiscales completos del cliente; uno interno, no. **Permite validar en el alta del contrato en vez de descubrirlo al emitir** |

## H.5 Una advertencia sobre el documento interno

Un documento que no se reporta **no puede llamarse «factura»** en su
representación impresa ni parecerlo. Debe ir rotulado como documento interno o
de cobro, sin numeración que imite la fiscal y sin CUFE ni QR.

No es una objeción al diseño —hay usos legítimos: enlaces entre sedes propias,
equipos de la empresa, cuentas de cortesía, empresas aún no obligadas—, es que
la distinción visual es lo que protege a quien lo use. Y por lo mismo:
**la asignación de grupo y todo cambio de grupo deben quedar auditados**.

---

# I. Plan de implementación

Cada fase termina con la suite en verde antes de pasar a la siguiente.

### Fase 0 — Decisiones (§J). No se toca código.

### Fase 1 — Entidad Empresa
`companies`, `company_tax_responsibilities`, `branches.company_id`, quitar las
dos UNIQUE globales. Comando de migración idempotente con `--dry-run`.
**Termina cuando:** existen dos empresas con datos y ninguna consulta cruza.

### Fase 2 — Aislamiento y autorización
Traits con global scope, `company_id` en las ~20 tablas, `Gate::before`,
pruebas de aislamiento por modelo.
**Es la fase más importante y la que no se puede acortar.**

### Fase 3 — Contexto y prelogin
`OperatingContext`, `ContextResolver`, middleware, pantalla de selección,
cambio en caliente, **eliminación de `GET /user/branches`**.
**Termina cuando:** un usuario con dos empresas elige, cambia sin cerrar sesión,
y no puede forzar por URL un contexto que no le corresponde.

### Fase 4 — Modalidad consolidada
`operation_mode`, `branchIds` en el contexto, selector de sucursal al crear,
indicador permanente.
**Termina cuando:** en consolidado se crean documentos en la sucursal correcta y
las reglas de cada sucursal se siguen respetando.

### Fase 5 — Grupos de contrato
`contract_groups`, CRUD, `contracts.contract_group_id`, grupo por defecto,
validación en alta de contrato.

### Fase 6 — Numeración unificada
`document_sequences` + `DocumentNumberService`. Migración de los tres
contadores, **una a una, verificada**. La de contratos va primero por ser la de
menor riesgo fiscal.

### Fase 7 — Filtros y listados
Grupo, empresa y sucursal en los buscadores, siempre intersecados con el
contexto.

### Fase 8 — Datos fiscales y catálogos
Campos de `clients`, `companies` y `services`. Catálogos DANE y DIAN sembrados
desde los `.gc`. Informe de completitud fiscal.

### Fase 9 — Decisión de facturación electrónica
`ElectronicInvoicingDecider`, `electronic_invoicing_enabled` por empresa,
`contract_group_id` congelado en la factura, serie interna separada.
**Termina cuando:** un contrato de grupo electrónico y otro de grupo interno
producen facturas correctas por caminos distintos, **sin que exista todavía
ninguna conexión con la DIAN**.

### Fase 10 — Documento electrónico
`electronic_documents`, resoluciones, rangos, certificados, XML, CUFE, firma.

### Fase 11 — Transporte y asincronía
Interfaz `DianTransport`, reintentos, estados, idempotencia.

### Fase 12 — Habilitación y producción gradual

**Las fases 1–9 no dependen de ninguna decisión pendiente sobre la DIAN.** Se
puede llegar hasta ahí con valor entregado y sin haber elegido proveedor
tecnológico.

---

# J. Decisiones que necesito de ti antes de empezar

| # | Decisión | Opciones | Mi recomendación |
|---|---|---|---|
| **D1** | ¿La modalidad A/B es de la empresa o del usuario? | Empresa · Usuario · Ambas | **De la empresa** (habilita el modo), y el conjunto de sucursales del usuario decide qué ve dentro. Así el gerente y el cajero conviven sin dos arquitecturas |
| **D2** | ¿Puede una empresa no tener sucursales? | Sí · No | **No.** Siempre al menos una, creada automáticamente. Una empresa sin sucursal obliga a que `branch_id` sea nullable en todo el sistema, y eso contamina cada consulta |
| **D3** | ¿Un usuario puede pertenecer a varias empresas? | Sí · No · Solo superadmin | Tú dices que sí (Usuario A → Empresa 1 y 2). Lo implemento así, **pero con el rol por empresa-sucursal**, nunca global |
| **D4** | ¿El cliente es de la empresa o de la sucursal? | Sucursal (hoy) · **Empresa** | **Empresa.** En los datos reales que analizamos, 215 personas tienen contratos en más de una sucursal, y ante la DIAN son **un solo adquiriente** |
| **D5** | Las facturas internas, ¿comparten serie con las fiscales? | Sí · **No** | **No.** Serie propia. Consumir consecutivos DIAN con documentos que nunca se reportan agota el rango autorizado y deja huecos que hay que justificar |
| **D6** | Las notas internas, ¿dónde numeran? | `document_sequences` · `numbering_ranges` | **Igual que las facturas**: interna si su grupo es interno, fiscal si es electrónico |
| **D7** | ¿Cambiar el grupo de un contrato afecta a facturas ya emitidas? | Sí · **No** | **No, nunca.** Por eso `contract_group_id` se copia a la factura al emitirla |
| **D8** | ¿Los planes y servicios son de empresa o de sucursal? | | **Servicio → empresa** (lleva UNSPSC e IVA: es fiscal). **Plan → sucursal**, con el precio de la empresa como valor por defecto |
| **D9** | ¿Migro `branches.contract_prefix` a `document_sequences`? | Sí · Dejarlo | **Sí, pero en la fase 6 y con `--dry-run`.** Es la de menor riesgo fiscal y sirve de ensayo para las de factura |

---

# K. Lo que podríamos estar olvidando

Revisión crítica de mi propio plan.

1. **`PdfBranding` y las plantillas de correo imprimen datos de la sucursal.**
   🔎 Si el NIT sube a la empresa, hay que revisar qué imprime cada plantilla.
   No lo verifiqué archivo por archivo.

2. **Las 7 pantallas paginadas y los informes** cruzan sucursales hoy sin
   pensarlo. En consolidado hay que decidir si un informe suma varias sucursales
   o las separa. ⏳ No lo cubrí en las fases.

3. **Los 2 jobs existentes** (`GeneratePendingInvoicesPdf`, `ImportOltOnts`) leen
   contexto de sesión o de sus argumentos. 🔎 Hay que revisarlos en la fase 2.

4. **El módulo de copias de seguridad.** Si mañana hay varias empresas, un
   backup restaurado revuelve contextos. Y cuando lleguen los certificados, hay
   que verificar explícitamente que **no viajan** en el backup general.

5. **La suite tarda ~20 minutos en esta máquina.** Con las pruebas de
   aislamiento crecerá bastante. Conviene decidir pronto si se separan en un
   grupo que corra aparte.

6. **`Contract` no tiene `scopeDeSucursal` hoy** y es el modelo más consultado.
   Es el que más se beneficia del global scope y también donde más probable es
   que un scope mal puesto rompa algo. Merece ir primero en la fase 2, no
   último.

7. **Empresas con el mismo NIT.** ⏳ ¿Puede haber dos filas `companies` con el
   mismo NIT (por ejemplo, para separar operaciones)? Mi diseño lo prohíbe con
   UNIQUE. Si alguna vez hace falta, esa restricción hay que quitarla **antes**
   de tener datos.

---

**No he tocado nada. Dime qué decisiones cierras de la tabla J y empiezo por la
fase 1.**
