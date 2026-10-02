# Acciones masivas: historial y reversión segura

Qué se ejecutó sobre muchos registros a la vez, quién lo hizo, qué
cambió en cada uno, y cómo deshacerlo **sin pisar lo que alguien tocó
después**.

**Dónde:** Sistema → Acciones masivas. Solo superadministrador.

---

## 1. Qué problema resuelve

Una importación de 800 contratos mal armada, un corte masivo lanzado
con la lista equivocada, una corrida de facturación de un período que
no tocaba. Hasta ahora cada uno de esos procesos dejaba, como mucho,
una línea en la auditoría: servía para saber **qué pasó**, no para
**deshacerlo**. Lo que faltaba no era la bitácora —que ya existe— sino
guardar, registro por registro, **cómo estaba antes**.

---

## 2. Las dos piezas

**`mass_actions`** — la operación: tipo, estado, usuario, sucursal,
contadores, un resumen propio de cada proceso (el archivo, el período,
el umbral) y el enlace con su reversión.

**`mass_action_items`** — un registro afectado: qué se tocó, su
etiqueta legible (`EGP000123`), el estado, y **`before`/`after` con
solo los campos que cambiaron**.

> `after` no es decoración: es contra lo que se compara el estado
> **actual** antes de revertir. Si no coincide, alguien lo cambió
> después y no se toca.

Las tablas que ya existían —`billing_runs`, `contract_cutoffs`,
`ont_import_runs`— **siguen donde estaban** con sus datos propios y sus
pantallas. La acción masiva las enlaza; no las sustituye.

---

## 3. Estados

| Estado | Qué significa |
|---|---|
| `procesando` | Todavía corriendo. No se revierte: no se sabe qué va a dejar |
| `completada` | Terminó sin errores |
| `completada_con_errores` | Terminó, con registros que fallaron |
| `fallida` | No llegó a hacer nada útil |
| `revirtiendo` | Reversión en curso |
| `revertida` | Deshecha del todo |
| `reversion_parcial` | Se deshizo lo que se pudo; quedaron conflictos |

Y por registro: `ok`, `omitido`, `error`, `revertido`, `conflicto`,
`no_reversible`.

> **Omitido no es error.** Omitido es una decisión del sistema —ese
> contrato no debía dos meses—: la operación hizo lo correcto. Error es
> que algo se rompió. Juntarlos haría que una corrida sana pareciera un
> desastre.

---

## 4. Qué se registra y qué se revierte

| Operación | Se registra | Reversión |
|---|---|---|
| **Corte masivo por cartera** | Sí | Devuelve el estado anterior y **restablece el servicio** (ONT y PPPoE) |
| **Corte masivo de PPPoE** | Sí | Rehabilita el secret en el Mikrotik |
| **Importación de clientes** | Sí | **Borra** los contratos creados y lo que cuelga |
| **Corrida de facturación** | Sí | **Anula** las facturas anulables — no las borra |
| **Importación de ONTs** | Sí | No se revierte automáticamente |

---

## 5. Reversión segura: las cuatro reglas

### 5.1 No sobrescribe cambios posteriores

Antes de tocar un registro se comprueba que siga **exactamente** como
lo dejó la acción.

```
Esperado (lo que dejó el corte): Suspendido
Estado actual:                   Suspendido      → se revierte

Esperado:                        Suspendido
Estado actual:                   Retirado        → CONFLICTO, no se toca
```

El conflicto se muestra con las tres versiones y su motivo. **Nunca se
sobrescribe en silencio.**

> El conflicto es del **intento**, no del registro: el ítem conserva su
> estado `ok` —la acción sí lo cambió— y guarda el motivo por el que
> hoy no se pudo deshacer. Así se puede reintentar cuando se resuelva
> lo que estorbaba, y la acción no se da por revertida sin estarlo.

### 5.2 No se revierte dos veces

La acción se bloquea (`lockForUpdate`) y se comprueba dentro de la
transacción: dos pestañas abiertas o un doble clic llegan a la vez, y
sin eso las dos pasarían. Además cada ítem lleva la fecha en que se
revirtió — un trabajo que se reintenta no vuelve a tocarlo.

### 5.3 No lo carga todo en memoria

Los ítems se recorren con `chunkById` en lotes de 200: esto funciona
igual con 800 que con 50.000.

### 5.4 Un registro no tumba a los demás

Cada ítem va en su **propia transacción**. El que falla queda marcado
con su motivo y los otros ochocientos siguen.

---

## 6. Lo que la reversión se NIEGA a hacer

Tres hechos que una fila borrada no puede deshacer:

| Freno | Por qué |
|---|---|
| **Factura con pagos** | Entró dinero y está en el cuadre de una caja. Borrarla deja ese dinero sin documento que lo soporte |
| **Factura validada por la DIAN** | Existe en sus registros con su CUFE. Borrarla aquí no la borra allá: los dos lados dejan de coincidir |
| **Notas crédito o débito** | Son documentos con su propio consecutivo, emitidos y entregados |

Cualquiera de los tres deja el registro en **conflicto** con su motivo,
para que lo decida una persona.

> Una corrida de facturación **nunca se borra**: se anula lo anulable.
> Una factura gasta un consecutivo de un rango autorizado que no se
> recupera, y el hueco hay que justificarlo ante la DIAN.

---

## 7. Cómo se revierte, paso a paso

1. **Sistema → Acciones masivas**. Filtrar por tipo, estado, usuario o
   fecha.
2. **Ver** la acción: información general, resumen, y los registros
   afectados con su antes/después.
3. **Revertir acción**. Lleva a la confirmación, que **antes de pedir
   nada** dice cuántos se pueden deshacer, cuántos están en conflicto y
   por qué, y qué va a pasar exactamente.
4. Escribir **REVERTIR**. No es un «¿está seguro?»: obliga a leer.
5. Al terminar se abre la **acción de reversión**, con su resultado.

Las dos acciones quedan enlazadas en los dos sentidos: desde la
original se ve quién la deshizo, y desde la reversión cuál deshizo.

---

## 8. Añadir un proceso masivo nuevo

Tres pasos, y nada más se entera:

**1. Un caso en `MassActionType`** con su etiqueta e icono.

**2. El proceso avisa**, sin cambiar su firma ni lo que devuelve:

```php
$accion = $recorder->abrir(
    MassActionType::LoQueSea,
    'Descripción de lo que se va a hacer',
    summary: ['lo' => 'propio del proceso'],
    source: $registroPropio,      // opcional
);

foreach (...) {
    $recorder->registrar($accion, $modelo, 'EGP000123',
        antes:   ['status' => 'Activo'],
        despues: ['status' => 'Suspendido'],
    );
}

$recorder->cerrar($accion);
```

> `antes` y `despues` llevan **solo los campos que cambian**. Con
> 50.000 ítems, copiar el registro entero multiplica la base para
> guardar cuarenta columnas que nadie va a mirar.

**3. Si se puede deshacer**, una clase que implemente
`RevierteUnaAccionMasiva` y una línea en `MassActionRegistry`. La
interfaz son dos métodos separados a propósito:

- `revisar()` responde «¿puedo tocar esto?» **sin tocar nada**.
- `revertir()` lo deshace, y solo se le llama si lo primero dijo que sí.

Juntarlos es como nacen las reversiones que pisan el trabajo de otro:
basta un `return` mal puesto para que la comprobación se salte y el
cambio no.

Lo que no esté en el registro **no se revierte**, y esa es la respuesta
correcta: mejor que el historial lo diga a que un botón prometa algo
que no puede cumplir.

---

## 9. Seguridad

Reservado al **superadministrador**, y por **rol activo de la sesión**
—no por un permiso marcable en el módulo de roles—. Es el mismo
criterio que la trazabilidad y las copias de seguridad, por la misma
razón: desde aquí se deshacen cortes, se anulan facturas y se borran
contratos.

**Dos puertas**, y protegen cosas distintas:

- el middleware `superadmin` en la ruta → la pantalla;
- `MassActionPolicy` → la acción concreta sobre una acción concreta, y
  si esa acción se puede revertir siquiera.

Escribir la URL a mano no sirve: hay prueba de ello.

---

## 10. Dónde está en el código

| Qué | Dónde |
|---|---|
| Registrar una operación | `app/MassActions/MassActionRecorder.php` |
| Deshacerla | `app/MassActions/MassActionReverter.php` |
| Qué estrategia deshace cada tipo | `app/MassActions/MassActionRegistry.php` |
| El contrato de una estrategia | `app/MassActions/RevierteUnaAccionMasiva.php` |
| Las estrategias | `app/MassActions/Reversiones/` |
| Estados y tipos | `app/MassActions/Enums/` |
| Modelos | `app/Models/MassAction.php`, `MassActionItem.php` |
| Pantalla | `app/Http/Controllers/MassActionController.php` |
| Autorización | `app/Policies/MassActionPolicy.php` |

Pruebas: `tests/Feature/System/MassActionsTest.php` (registro,
reversión, conflictos, doble reversión, seguridad) y
`MassActionReversalGuardsTest.php` (lo que la reversión debe negarse a
hacer).
