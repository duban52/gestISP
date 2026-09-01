# Traslado de servicio: la caja NAP donde queda el cliente

Pruebas: [`tests/Feature/TechnicalOrders/RelocationNapTest.php`](../tests/Feature/TechnicalOrders/RelocationNapTest.php)

## El problema

Un traslado es **la única clase de orden que mueve el servicio de una caja NAP a
otra**. El cliente se muda, la acometida se tiende desde otro sitio, y el puerto
que ocupaba queda libre.

Antes eso no se registraba en el momento. La pantalla del técnico mostraba las
cajas cercanas como **sugerencia**, con una nota que decía literalmente que el
puerto lo asignaba la oficina después, en la ficha del contrato. Entre una cosa
y otra se perdía — y la ocupación de las cajas dejaba de coincidir con la
realidad **justo en las órdenes que la cambian**.

El único que sabe en qué caja y en qué puerto quedó el cliente es quien estuvo
allí.

## Los dos momentos en que se pregunta

El traslado se puede capturar en cualquiera de los dos; el que llegue primero lo
resuelve.

### 1. Al mover la ONT de puerto PON

Pantalla: **ONT → Sin autorizar → Mover ONT a nuevo puerto**

Mover una ONT de puerto PON casi siempre significa que el cliente se mudó. El
modal ofrece ahora la caja y el puerto, **limitados a las cajas de ese puerto
PON de destino** — que son las únicas por las que puede llegar la señal.

Es **opcional**: mover una ONT también puede ser un rebalanceo de la red sin que
el cliente se haya movido de sitio.

La comprobación la hace `OntController::ocuparPuertoNap()`, que ya existía para
la activación: valida que la caja sea de la sucursal activa **y** que cuelgue
del mismo puerto PON donde quedó la ONT. Se llama **después** de actualizar la
ONT, porque necesita el puerto nuevo y no el viejo.

### 2. Al cerrar la orden el técnico

Pantalla: **Gestión técnica → procesar orden**

Cuando la orden es un traslado aparece un bloque dentro del formulario con la
caja y el puerto. Aquí **no** se limita al puerto PON: el cliente se mudó y la
caja nueva puede estar en cualquier parte de la sucursal. Las cajas cercanas
siguen mostrándose arriba, pero ya solo como pista.

Si el contrato ya tenía puerto —porque la ONT se movió antes, o porque la orden
se devolvió— viene **preseleccionado**: el técnico solo confirma.

## La regla

**Un traslado no se cierra sin decir dónde quedó el servicio.** Se valida en el
servidor, no en el JavaScript.

### La excepción, que no es silenciosa

Si la caja no está documentada en el sistema, el técnico no puede inventársela
y tampoco puede quedarse con la orden abierta en mitad de la calle. Marca **«La
caja no está registrada en el sistema»**, explica por qué, y la orden se cierra.

El motivo queda en la trazabilidad como `naps.port_pending`, contra la orden,
para que alguien la registre después.

Es el mismo criterio que ya usaba la ubicación del cierre: **distinguir «no se
pudo» de «no se hizo»**, en vez de bloquear a alguien que está trabajando.

Comparado con las otras reglas del módulo:

| Dato | ¿Bloquea? | Por qué |
|---|---|---|
| Material en una instalación | **Sí** | Siempre se instala equipo y cable |
| Firma del cliente | **Sí** (la primera vez) | El cliente está delante |
| Ubicación del cierre | No | El GPS puede fallar y no es culpa del técnico |
| **Caja NAP en un traslado** | **Sí, con escape explicado** | El técnico siempre sabe dónde conectó; lo que puede faltar es la caja en el sistema |

## Detalles que costaron un rato

**El detalle de la orden llega de muchas formas.** «Traslado de servicio»,
«traslado de servicio», con y sin tilde, con sufijos. Por eso
`TechnicalOrder::esTraslado()` resuelve con `OrderDetailMap::clave()` y no
comparando cadenas: comparar texto dejaría fuera media docena de variantes
reales. Hay una prueba con proveedor de datos que fija esto.

**La sucursal de una caja no está en `nap_boxes`.** Cuelga de su red óptica
(`NapBox::scopeDeSucursal` filtra por `network.branch_id`). La primera versión
de la comprobación miraba `$napBox->branch_id`, que es `null`, y habría
rechazado **todas** las asignaciones. Lo detecté antes de las pruebas, pero es
la clase de fallo que pasa desapercibida si nadie prueba el camino feliz.

**El bloque tiene que estar dentro del `<form>`.** La primera colocación lo dejó
en la columna izquierda de la pantalla, fuera del formulario: el navegador no
habría enviado `nap_port_id` y la validación habría fallado siempre.

**Los puertos ocupados se ocultan, menos el propio.** En una orden devuelta, el
puerto que ya ocupa ese contrato figura como ocupado y desaparecería de la
lista, dejando al técnico sin poder confirmarlo. Se filtra por
`estaDisponible() || $p->id === $puertoActual`.

**El listado de cajas va incrustado en la página, no en una llamada aparte.** El
técnico puede estar en la calle con mala señal: si la lista dependiera de una
petición, no podría cerrar la orden.

**`liberarPuerto()` solo borraba el id.** `asignarPuerto()` mantiene en sintonía
`nap_port_id` y el texto legible `contracts.nap_port`, pero al liberar solo se
limpiaba el id: la ficha seguía mostrando «NAP-001 / P3» para un contrato que ya
no ocupaba ningún puerto. Se notaba justo después de un traslado, que es cuando
esa ruta empezó a usarse. Corregido, con prueba.

**Nunca `@php(...)` en línea.** El compilador de Blade la empareja con el
siguiente `@endphp` del archivo y se traga todo el HTML de en medio como si
fuera PHP. La vista compila sin quejarse con `view:cache` y revienta al
renderizar. Ya había pasado antes en `olts/show.blade.php` y volvió a pasar aquí
con el aviso de liberar; hay cero apariciones de esa forma en el proyecto y
conviene que siga así.

## El puerto viejo: dos liberaciones distintas

Esto es lo que más confunde del módulo, así que conviene separarlo.

### La lógica: se libera sola

La ocupación **no se almacena**. `NapPort::estaOcupado()` es simplemente
*«¿existe un contrato que me apunta?»*:

```php
public function contract()          { return $this->hasOne(Contract::class, 'nap_port_id'); }
public function estaOcupado(): bool { return $this->contract !== null; }
```

Cuando el contrato pasa a apuntar al puerto nuevo, el viejo se queda sin nadie
apuntándole y **queda libre por definición**. No hay ningún código que lo
libere, porque no hay ningún campo que poner a `false`. Y `contracts.nap_port_id`
tiene un índice **UNIQUE** —la migración lo llama «la regla de oro del módulo»—,
así que dos técnicos cerrando traslados a la vez no pueden meter dos contratos
en el mismo puerto: lo impide la base de datos, no el código.

### La física: hay que ir a desconectarla

Lo que **no** se entera es la caja. La acometida vieja sigue puesta, y el
próximo cliente que llegue a ese puerto se encuentra un pigtail ajeno — y hay
que volver al sector.

Por eso, al cerrar un traslado, la bandeja del técnico muestra un aviso que
nombra **la caja, el puerto y la dirección**, con enlace a mapas en el móvil.
Sale justo al cerrar, que es cuando el técnico todavía está cerca. No se cierra
con la X: es lo único que puede evitar un segundo viaje.

El aviso **no** sale cuando no hay nada que desconectar: si el contrato no tenía
caja registrada, o si el técnico confirmó el mismo puerto al cerrar una orden
devuelta.

### Si la caja nueva no está registrada

El puerto viejo **también se suelta**. El cliente ya no está ahí aunque no
sepamos dónde quedó; dejar el contrato apuntando al puerto viejo lo bloquearía
para siempre — la caja diría que lo ocupa alguien que se mudó.

Quedan las dos cosas en la trazabilidad: `naps.port_pending` con el motivo, y
`naps.port_released` con el puerto que se soltó.

## Lo que no se guarda

La ocupación de un puerto **no se almacena**: se deduce de qué contrato apunta a
él. Al reasignar, el puerto viejo queda libre solo, sin tocarlo. Hay una prueba
que lo comprueba, y está ahí para el día que alguien quiera añadir un campo
`ocupado` «para ir más rápido» — el día que eso pase, el inventario empieza a
mentir.
