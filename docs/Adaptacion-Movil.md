# Adaptación a pantallas pequeñas

## Por qué existe

Tres módulos de GestISP se usan de verdad desde el teléfono:

- **Gestión técnica.** El técnico abre su bandeja en la calle, mira qué le
  toca, va a la dirección y cierra la orden ahí mismo, con una mano.
- **Cuentas PPPoE.** Se consultan mientras se diagnostica: si el cliente
  está conectado, desde qué IP y cuándo fue la última vez.
- **ONT.** Se consultan y se activan con el equipo delante.

El resto del sistema (facturación, caja, informes, planta de fibra) se
usa sentado frente a un computador y no necesita este trabajo.

AdminLTE es *responsive* en su estructura: el menú se pliega, las
columnas se apilan. Pero sus **tablas no lo son**. Una tabla de once
columnas dentro de `.table-responsive` no se rompe: se convierte en una
tira que hay que desplazar lateralmente, y en un teléfono eso significa
perder de vista la primera columna —justo la que identifica la fila— en
cuanto se llega a la tercera.

## Cómo se resuelve

Una hoja compartida, [`public/css/gestisp-movil.css`](../public/css/gestisp-movil.css),
que **cada fila la convierte en una ficha** por debajo de 768 px (el
mismo punto de corte que Bootstrap 4 usa para `md`).

La tabla sigue siendo la misma tabla: el mismo `<table>`, el mismo
DataTable, el mismo HTML. Solo cambia la presentación. Eso es
deliberado, y es lo que hace que el buscador, la ordenación y la
paginación sigan funcionando sin escribir una segunda versión de cada
pantalla que luego habría que mantener en paralelo.

El rótulo de cada celda sale del atributo `data-label` de la propia
celda:

```html
<td data-label="Última conexión">hace 3 días</td>
```

```css
.tabla-movil td::before { content: attr(data-label); }
```

Así los nombres de columna no se duplican en JavaScript ni hay que
inyectarlos al cargar la página.

### La hoja se enlaza vista por vista, no en el layout

```blade
@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
```

Acotar el alcance a los tres módulos es lo que garantiza que **no se
altere ninguna pantalla que hoy funciona bien**. Meterla en el layout
general habría cambiado, de golpe, las tablas de todo el sistema.

## Clases disponibles

| Clase | Qué hace |
|---|---|
| `.tabla-movil` | Convierte la tabla en fichas. Va en el `<table>`. |
| `data-label="…"` | Rótulo de la celda dentro de la ficha. Va en cada `<td>`. |
| `.celda-principal` | Encabeza la ficha, con fondo propio y sin rótulo. Es el dato por el que se reconoce la fila. |
| `.celda-acciones` | Los botones, repartidos a lo ancho y con 40 px de alto. |
| `.toque` | Objetivos táctiles de 44 px y campos a 16 px. |
| `.acciones-movil` | Los botones de cabecera se apilan a lo ancho. |
| `.filtros-movil` | Formulario de filtros compacto. |
| `.modal-movil` | El modal ocupa la pantalla completa. |
| `.solo-escritorio` / `.solo-movil` | Se ocultan por debajo / por encima de 768 px. |
| `.romper-texto` | Permite partir seriales y usuarios largos. |

### Tres trampas que cuestan una tarde

Las tres son de DataTables, y las tres se manifiestan igual —la ficha
más ancha que la pantalla— por causas distintas. Conviene conocerlas
antes de tocar esta hoja.

**1. Escribe el ancho de la tabla en un atributo `style`.** Al arrancar
mide las columnas y le pone al `<table>` algo como
`style="width: 1284px"`. Un estilo en línea gana a la hoja, así que las
fichas heredaban el ancho de la tabla de escritorio, se salían de la
pantalla y el último botón de cada fila quedaba cortado. De ahí el
`!important` en el ancho de `.tabla-movil`: es lo único que gana a un
estilo en línea.

**2. Pone `box-sizing: content-box` en las celdas.** Su hoja trae
`table.dataTable td { box-sizing: content-box }` —lo necesita para medir
columnas—. Con eso, el `width: 100%` de la celda **no incluye** su
relleno, y cada celda sobresale los 22 px de sus dos rellenos: la ficha
queda más ancha que la tarjeta y aparece un desplazamiento lateral de
unos 20 px **dentro de `.table-responsive`**, no de la página. Se nota
al arrastrar de izquierda a derecha: el texto se corta por el lado
izquierdo. Medido: celda de 320,2 px dentro de un contenedor de 303.

Aquí el `!important` tampoco es pereza — es aritmética de
especificidad: `table.dataTable td` (una clase, dos elementos) pesa más
que `.tabla-movil td` (una clase, un elemento), así que sin él la regla
de DataTables gana.

**3. El desplazamiento no es de la página.** Buscarlo con
`document.documentElement.scrollWidth` da cero y despista. Hay que
recorrer los elementos con `overflow-x: auto|scroll` y comparar su
`scrollWidth` con su `clientWidth`:

```js
document.querySelectorAll('*').forEach(el => {
  const cs = getComputedStyle(el);
  if (el.scrollWidth - el.clientWidth > 1 &&
      (cs.overflowX === 'auto' || cs.overflowX === 'scroll')) console.log(el);
});
```

Comprobado con DataTables real a 320, 375 y 1366 px: ningún contenedor
desborda, y en escritorio las celdas conservan su `content-box` y sus
anchos calculados.

**Las acciones que cambian datos van dentro de un `<form>`** (hace falta
el token CSRF). Ese `form` es el hijo directo del contenedor flexible,
no el botón, así que las propiedades de reparto puestas en el botón no
hacían nada: el botón envuelto salía estrecho y los de al lado anchos.
El reparto tiene que ir también en el `form`.

Y una decisión de forma: las acciones se reparten **dos por fila**
(`flex-basis: calc(50% - .2rem)`), no "las que quepan". Con reparto
libre la última cae sola a la línea siguiente ocupando todo el ancho —
con cuatro acciones eso deja un «Eliminar» rojo a pantalla completa
justo donde cae el pulgar.

### Decisiones que conviene conocer

**La celda principal duplica información a propósito.** En el listado de
ONT, el serial encabeza la ficha y debajo repite el estado y la potencia,
que en escritorio son columnas propias. Esas columnas se marcan
`.solo-escritorio`: en el teléfono se ven una sola vez, arriba, que es
donde se leen.

**Los botones llevan texto en el teléfono.** Un icono suelto no dice qué
hace, y en un móvil no hay ratón que muestre el *tooltip*. Cuatro iconos
iguales en fila, además, invitan a pulsar el que no era — y uno de ellos
borra la cuenta:

```blade
<i class="fas fa-cogs"></i>
<span class="d-md-none ml-1">Procesar</span>
```

**Una sola barra de acciones por pantalla.** En el listado de PPPoE los
botones estaban repartidos entre la cabecera y una tarjeta propia, con
«Cortes masivos» saliendo dos veces. Ahora la cabecera lleva solo el
título y todo lo demás va en una barra que se apila a lo ancho por
debajo de 768 px, con las acciones del día a día primero y la
importación al final —es tarea de puesta en marcha y se hace una vez—.
El orden se invierte en escritorio con `order-md-1` / `order-md-2`.

**Los `<thead>` se ocultan con `position`, no con `display:none`.** Así
los lectores de pantalla siguen anunciando los encabezados.

**Menos filas por página.** `pageLength: enMovil ? 10 : 25`. Cada fila
ocupa una ficha entera; veinticinco fichas son un desplazamiento
interminable.

**Se esconde el selector "mostrar N".** `dom: enMovil ? 'ftip' : 'lfrtip'`.
Ocupa una línea entera y no aporta nada en un teléfono.

**Los filtros se pliegan.** Seis filtros consumen media pantalla antes de
llegar a los datos. Se pliegan por defecto —y arrancan **abiertos si ya
hay filtros puestos**, para que no parezca que la lista está incompleta
sin explicación:

```blade
<form class="card-body collapse d-md-block filtros-movil toque {{ $hayFiltros ? 'show' : '' }}">
```

La clase `d-md-block` es la que mantiene el panel siempre visible en
escritorio: el desplegable existe solo en el teléfono.

**En el listado de órdenes, la dirección lleva enlace a mapas.** Solo en
el móvil, que es donde sirve, porque el técnico va a ir hasta allí.

## Filas que pinta JavaScript

En *ONT sin autorizar* las filas no las pinta Blade: las añade DataTables
con los datos que devuelve la OLT. Ahí las etiquetas se ponen en
`createdRow`, **leyendo el encabezado de la propia tabla**, para que no
haya dos sitios donde mantener los mismos nombres de columna:

```js
const rotulos = $('#autofindTable thead th').map(function () {
    return $(this).text().trim();
}).get();

createdRow: function (fila) {
    $(fila).find('td').each(function (i) {
        if (i === 0)                        $(this).addClass('celda-principal').attr('data-label', '');
        else if (i === rotulos.length - 1)  $(this).addClass('celda-acciones').attr('data-label', '');
        else                                $(this).attr('data-label', rotulos[i] || '');
    });
}
```

## Paginación

Aparte de las tablas, se corrigió un defecto que afectaba a **todo el
sistema**, no solo al móvil.

Laravel 10 pagina con marcado de **Tailwind** por defecto. Todo el panel
es AdminLTE sobre **Bootstrap 4**. El resultado eran enlaces de página
sueltos y desalineados en las siete pantallas paginadas del sistema
—trazabilidad, clientes, pagos, movimientos de caja, categorías y
sesiones—, que es lo que se veía en la vista de trazabilidad.

Se arregla en un solo sitio, [`app/Providers/AppServiceProvider.php`](../app/Providers/AppServiceProvider.php):

```php
Paginator::useBootstrapFour();
```

La hoja móvil, además, envuelve la lista de páginas y le da 40 px de
alto a cada enlace: con muchas páginas la fila se salía del ancho.

## Vistas adaptadas

**Gestión técnica**

- `technicals_orders/my_technical_orders.blade.php` — bandeja del técnico
- `technicals_orders/index.blade.php` — listado general
- `technicals_orders/verification_orders.blade.php` — órdenes por verificar
- `technicals_orders/show_and_process_order.blade.php` — procesar la orden

**PPPoE**

- `pppoe/index.blade.php` — listado, filtros y exportación
- `pppoe/show.blade.php` — ficha de la cuenta
- `pppoe/cutoff.blade.php` — cortes masivos

**ONT**

- `onts/authorized/index.blade.php` — ONT autorizadas
- `onts/no-authorized/index.blade.php` — pendientes de activación
- `onts/show.blade.php` — ficha de la ONT
- `onts/import/index.blade.php` — importación desde una OLT

En `pppoe/show` y `onts/show` las tablas **no estaban envueltas en
`.table-responsive`**: desbordaban el ancho y arrastraban la página
entera hacia los lados. Se envolvieron las ocho.

## Pruebas

[`tests/Feature/Ui/MobileViewsTest.php`](../tests/Feature/Ui/MobileViewsTest.php)
— 12 pruebas.

Comprueban que cada vista enlaza la hoja, que las tablas llevan las
clases que el CSS necesita encontrar, que las fichas envuelven sus
tablas y que la paginación sale con marcado de Bootstrap 4 y no de
Tailwind.

Existen porque **esta clase de regresión no se nota en escritorio**: si
alguien reescribe una vista y se lleva por delante el enlace a la hoja o
la clase `.tabla-movil`, en un computador todo sigue igual y solo se
descubre cuando un técnico, en la calle, no puede leer su bandeja.
