# Georreferenciación de servicios y órdenes

Este documento explica **qué** se guarda, **por qué** y **dónde tocar** cuando haya
que cambiarlo. Los detalles de implementación están comentados en el propio
código; aquí está lo que no cabe en un comentario.

---

## 1. El problema que resuelve

Hasta ahora «dónde queda el servicio» era una dirección escrita a mano. En un ISP
eso no alcanza:

- El barrio se llama distinto según quién lo escriba.
- En zona rural y en buena parte de los barrios no hay nomenclatura: la
  dirección no lleva a ninguna parte.
- Nadie podía responder **«¿qué caja NAP le queda cerca a este cliente?»** sin ir
  al sitio o llamar al que conoce la zona.
- Una orden cerrada en la acometida del cliente y otra cerrada desde el sofá del
  técnico se veían **exactamente igual** en el sistema.

Con el punto en el mapa las cuatro cosas se resuelven solas.

---

## 2. Qué se guarda

### 2.1 La vivienda — `contracts`

| Columna | Para qué |
|---|---|
| `latitude`, `longitude` | `decimal(10,7)` ≈ 1 cm de resolución |
| `located_at` | Cuándo se marcó |
| `located_by` | Quién lo marcó (FK a `users`) |
| `location_source` | `mapa`, `dispositivo` u `orden` |

**Es opcional a propósito.** Hay miles de contratos vivos sin coordenadas y no se
puede obligar a salir a ubicarlos para poder seguir facturando. Cada pantalla
distingue «sin ubicar» de «ubicado»; **ninguna inventa un punto por defecto**.

`location_source` no es decorativo: un punto tomado con el GPS del técnico parado
en la puerta (`dispositivo`) merece más confianza que uno marcado a ojo desde la
oficina (`mapa`). El formulario lo pone solo: arranca en `mapa` y el botón
«Estoy aquí» lo cambia a `dispositivo`.

### 2.2 El cierre de la orden — `technical_orders`

| Columna | Para qué |
|---|---|
| `closing_latitude`, `closing_longitude` | Dónde estaba el técnico al procesar |
| `closing_accuracy_m` | Margen de error que reportó el dispositivo |
| `closing_located_at` | Cuándo se tomó |
| `closing_location_error` | Por qué **no** hay punto |

Son columnas **distintas** de las del contrato a propósito: compararlas es justo
lo que permite saber si el trabajo se hizo donde debía. Si se guardaran en el
mismo sitio no habría nada que comparar.

---

## 3. El semáforo del cierre

Lo calcula `App\Support\OrderLocationCheck` y lo pinta el detalle de la orden.

```
distancia_ajustada = max(0, distancia_real − margen_de_error_del_GPS)

  ≤ 150 m  → coincide      (verde)
  ≤ 500 m  → por revisar   (amarillo)
  > 500 m  → lejana        (rojo)
```

**Por qué se descuenta el margen de error.** El GPS de un celular miente, y
miente distinto en cada sitio: bajo techo, entre edificios o en zona rural sin
cobertura el error pasa de diez metros a varios cientos. Acusar a un técnico
porque su teléfono reportó 200 m de desvío **con 300 m de margen admitido** sería
injusto y, peor, haría que nadie volviera a fiarse del indicador. El sistema
señala, no sentencia.

**Dos estados que NO son sospecha.** Se distinguen aparte para que nadie los
confunda con una orden mal cerrada:

- `sin_ubicacion` — el dispositivo no dio posición (el motivo queda en
  `closing_location_error`).
- `sin_referencia` — el contrato todavía no está georreferenciado, así que no hay
  con qué comparar.

Los umbrales viven en las constantes `TOLERANCE_M` y `FAR_THRESHOLD_M` de
`OrderLocationCheck`. 150 m es aproximadamente una manzana urbana con holgura:
cubre que el técnico cerrara desde el poste, la caja NAP o la acera de enfrente.

---

## 4. La captura nunca bloquea

Al abrir la pantalla de procesar una orden, el navegador pide la ubicación
**solo**, sin que el técnico tenga que pulsar nada: si dependiera de un botón, la
mitad de las órdenes llegarían sin punto y el dato dejaría de servir para
comprobar nada.

Pero **no impide cerrar**. Un permiso denegado, un equipo sin GPS o un sótano sin
señal no pueden dejar a un técnico con el trabajo hecho y la orden abierta. En su
lugar se guarda el motivo, y ahí está la diferencia entre «no se pudo» y «no se
quiso» cuando alguien revise.

El `(0, 0)` —el punto nulo en mitad del Atlántico que devuelven algunos
dispositivos cuando responden sin haber fijado posición— **se descarta** y se
anota como fallo. Guardarlo pondría todas las órdenes a diez mil kilómetros del
cliente y dejaría el indicador en rojo permanente, que es la forma más rápida de
que nadie vuelva a mirarlo.

---

## 5. Sugerencia de caja NAP

`App\Services\NapFinder` responde «¿a qué caja conecto esta casa y en qué
puerto?». Aparece en:

- La pantalla del técnico, **solo** en órdenes de **instalación** y **traslado**
  (en una avería el cliente ya está conectado; sugerirle otra caja sería
  invitarlo a moverlo sin motivo).
- La ficha del contrato, bajo demanda («Ver cajas NAP cercanas»).
- El endpoint `naps.nearby`, que ahora también dice el puerto.

Dos cautelas que le dan sentido al resultado:

1. **Es una sugerencia, no una asignación.** La fibra no vuela en línea recta:
   entre la casa y la caja más cercana puede haber un río o una avenida sin
   cruce. Por eso se ofrecen varias candidatas ordenadas por distancia.
2. **La ocupación se calcula en el momento, nunca se guarda.** Es la misma regla
   del módulo de redes (ver `NapPort`): el puerto libre de ahora puede estar
   ocupado dentro de diez minutos. Por eso las cajas cercanas se piden por AJAX
   y no se incrustan al abrir la ficha.

El «siguiente puerto libre» es el de **menor número** disponible, que es el orden
en que se llenan las cajas en campo: así los libres quedan juntos al final y el
siguiente instalador no tiene que buscar hueco. Salta los ocupados y los dañados.

Las cajas en mantenimiento o retiradas no se sugieren nunca. Las cajas llenas
**sí** aparecen, sin puerto: saber que la caja de al lado no tiene cupo es justo
lo que dice dónde hay que ampliar la red.

---

## 6. Los mapas

Leaflet sobre OpenStreetMap: sin llave de API ni facturación, que es lo que lo
hace apto para un panel interno de uso diario.

| Archivo | Qué hace |
|---|---|
| `partials/leaflet-styles.blade.php` | Los `<link>` y los estilos de los marcadores. Va en `@section('css')` |
| `partials/leaflet-script.blade.php` | Carga Leaflet y arranca los mapas. Va en `@section('js')` |
| `partials/location-picker.blade.php` | Mapa para **elegir** un punto (rellena dos campos ocultos) |
| `partials/location-map.blade.php` | Mapa de **solo lectura** (uno o varios puntos, con línea entre ellos) |

**Cómo encajan.** En AdminLTE el contenido se pinta *antes* de que se carguen las
librerías de `@section('js')`: un mapa que se inicializara junto a su propio
`<div>` reventaría con «L is not defined». Por eso cada parcial deja solo su
configuración en un bloque JSON inerte
(`<script type="application/json" data-gestisp-map="…">`) y el motor —que ya
tiene Leaflet— los recoge.

**Arranque perezoso.** Los mapas se crean **cuando se ven**, no al cargar la
página: en el listado de órdenes hay un modal por fila, y arrancar cincuenta
mapas que nadie va a mirar cuesta memoria y una tanda de peticiones de teselas
por cada uno. De paso resuelve el otro problema clásico: un mapa creado dentro de
un contenedor oculto se dibuja partido porque su contenedor mide 0 px.

**En los PDF no se dibuja mapa.** Un PDF que dependiera de descargar teselas
tardaría en generarse y saldría en blanco cuando el servidor no tenga salida a
internet. El comprobante de la orden imprime el dato que sostiene el soporte: a
qué distancia del servicio se cerró.

---

## 7. Trazabilidad

Los cambios de columna ya los audita el sistema por su cuenta
(`AuditServiceProvider`), pero en crudo: dos números decimales no le dicen nada a
quien revisa. Por eso se escriben además estas entradas legibles:

| Acción | Cuándo | Qué cuenta |
|---|---|---|
| `contracts.located` | Al fijar o ajustar el punto | Cuánto se movió respecto de lo anterior |
| `contracts.location_cleared` | Al quitarlo | Las coordenadas que tenía |
| `technical_orders.closed` | Al procesar una orden | A cuántos metros del servicio se cerró |

Un ajuste de veinte metros es afinar; uno de tres kilómetros es un error o un
traslado sin documentar. Esa diferencia es la que hace útil la bitácora.

---

## 8. Número de contrato, nunca el id

Regla del sistema: **cada vez que se muestra un contrato se muestra su
consecutivo** (`ENG000123`), nunca el `id` interno de la tabla. El accesor es
`Contract::numero_visible`, que cae al id solo en los contratos anteriores a la
numeración por sucursal, para que la pantalla nunca quede vacía.

Se corrigió en facturas (pantalla y PDF), órdenes técnicas (listados, detalle,
verificación y comprobante), buscador de clientes, fichas de ONT y de cuenta
PPPoE, importación de ONTs, exportación de contratos y mensajes de error.

En la auditoría, `AuditLabels::identificar()` mira `contract_number` **antes** que
cualquier otro campo, para que la bitácora diga «el contrato ENG000123» y no
«el contrato N.º 45».

---

## 9. Dónde está cada cosa

```
app/Support/Geolocation.php          Distancia entre dos puntos (haversine) y validación
app/Support/OrderLocationCheck.php   El semáforo del cierre
app/Support/NapSuggestion.php        Una caja propuesta, con su puerto
app/Services/ContractGeolocator.php  Único sitio por donde se fija/quita el punto
app/Services/NapFinder.php           Cajas cercanas + siguiente puerto libre
app/Http/Controllers/ContractLocationController.php
```

El equivalente en SQL de la distancia está en `NapBox::scopeCercanasA`, que aplica
la misma fórmula **dentro de la consulta** para no traerse cientos de cajas a
memoria. Son dos implementaciones de lo mismo a propósito: en PHP se comparan dos
puntos concretos, en SQL se ordena una tabla.

## 10. Pruebas

```
tests/Feature/Contracts/ContractGeolocationTest.php
tests/Feature/TechnicalOrders/OrderLocationTest.php
```

Lo que defienden: que no entre basura (el `(0,0)`, contratos de otra sucursal,
una latitud sin su longitud), que quede anotado quién puso el punto, que el
puerto sugerido esté **de verdad** libre, que un GPS malo no convierta una orden
buena en sospechosa y —lo más importante— que **la falta de ubicación nunca
impida cerrar una orden**.
