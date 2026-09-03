# Datos fiscales y catálogos

Fase 8 de multiempresa. Trae al sistema todo lo que el XML de una factura
electrónica exige y que hasta ahora no existía en ninguna parte.

## Los catálogos

Las listas de códigos que publica la DIAN: tipos de documento, tipos de
organización, responsabilidades fiscales, formas y medios de pago, tarifas de
IVA, unidades de medida, monedas… y los códigos DANE de departamentos y
municipios, que viajan en el mismo paquete.

**Van en tabla, no en enums de PHP.** Cambian por resolución, y un código nuevo
no puede exigir un despliegue de código: son datos, no lógica.

### Una tabla y no doce

El plan hablaba de «~12 tablas». Son todas listas de código + nombre, sin
comportamiento propio y sin claves foráneas entre ellas — los códigos se guardan
como **texto** en cada documento, que es como los pide el XML y lo que además
evita que retirar un código rompa documentos ya emitidos.

Una sola tabla con discriminador hace lo mismo con mucho menos andamiaje, y
tiene una ventaja concreta: **añadir un catálogo nuevo no pide migración**. Con
doce tablas, cada lista nueva sería una migración, un modelo y un seeder.

### El problema de despliegue que el plan no contemplaba

Los `.gc` viven en el paquete de ayuda de la DIAN, que **está en `.gitignore` y
no existe en el servidor**. Sembrar directamente desde ahí ataría el despliegue
a tener ese paquete a mano.

```bash
php artisan dian:importar-catalogos --dry-run   # qué se leería
php artisan dian:importar-catalogos             # genera database/data/dian/*.json
php artisan db:seed --class=FiscalCatalogSeeder # los mete en la tabla
```

El primer paso se hace **una vez, en desarrollo**, y deja el resultado como JSON
en `database/data/dian/`, que **sí se versiona**: los catálogos son datos de
referencia y su sitio es el control de versiones. Cuando la DIAN publique una
versión nueva se repite y **el cambio se revisa en el diff de git** — que es
exactamente donde uno quiere ver un cambio de catálogo fiscal.

Son 18 catálogos y 2.814 códigos, con 1.122 municipios y 33 departamentos.

### Sobre la codificación

Los archivos son **UTF-8 de verdad**, aunque una consola de Windows los enseñe
mal. Se comprobó byte a byte antes de importar: `Medellín` son los bytes
`\xc3\xad`, que es UTF-8 correcto. No se «arregla» nada — reinterpretar bytes
correctos como Latin-1 es justo lo que convierte «Medellín» en «MedellÃ­n».

### Los códigos retirados no se borran

Un código que desaparece del catálogo se marca **inactivo**. Deja de ofrecerse
al dar de alta, pero conserva su nombre: los documentos ya emitidos lo llevan, y
borrarlo dejaría sin nombre a lo que ya se emitió.

## Los datos del cliente

`clients` no tenía **ninguno**. Ahora tiene el código del tipo de documento, el
dígito de verificación, el tipo de organización, la dirección fiscal, los
códigos DANE, el código postal y el país, más una tabla de responsabilidades
fiscales.

### La dirección fiscal no es la del contrato

Hoy la única dirección vive en el contrato, y es la del **servicio** — dónde
está instalada la antena. La fiscal es dónde recibe correspondencia el
contribuyente, y no tienen por qué coincidir: un cliente con tres contratos
tiene tres direcciones de servicio y una sola fiscal.

### El fallo que destapó migrar el tipo de documento

El formulario tenía las opciones escritas a mano, y una decía:

```html
<option value="Persona Jurídica">Pasaporte</option>
```

Quien elegía «Pasaporte» guardaba «Persona Jurídica». Nadie se enteraba, porque
el valor guardado no se enseñaba en ninguna parte junto a la etiqueta que lo
produjo. Sacar la lista al catálogo lo corrige de raíz.

Por eso la migración **no traduce** «Persona Jurídica»: no se puede saber cuál
de las dos cosas quiso decir quien lo eligió. Se deja nulo y sale en el informe
de completitud para que alguien lo decida mirando el cliente. Inventarse un
código fiscal es peor que no tenerlo.

### El duplicado se detecta por código, no por texto

El `unique` iba por el texto libre, así que «Cedula de ciudadania» y «Cédula de
ciudadanía» eran dos tipos distintos y el mismo cliente entraba dos veces. Ahora
va por el código.

### `type_document` se conserva

El texto libre se queda como red de seguridad durante la transición —igual que
`branches.nit`— y se sigue rellenando desde el catálogo al guardar: hay
pantallas y exportaciones que todavía lo imprimen, y dejarlo vacío las dejaría
en blanco.

## Todo nace vacío, y es deliberado

Exigir datos fiscales al dar de alta dejaría sin poder trabajar a quien solo
quiere instalarle internet a alguien — que es el 100% de los casos hasta que la
facturación electrónica esté encendida.

Van en su propia tarjeta, plegada, con un aviso de que solo hacen falta si ese
cliente va a recibir factura electrónica.

Lo que hay en su lugar es el informe.

## El informe de completitud fiscal

Contesta **antes** la pregunta que si no solo se contesta el día que se intenta
emitir: qué falta.

- **La empresa va primero y aparte.** Sin sus datos no se emite nada, por muchos
  clientes completos que haya. Sale marcado como bloqueante.
- **Solo mira lo que va a facturar electrónicamente.** Un cliente cuyos
  contratos son todos de grupos internos no necesita datos fiscales, y contarlo
  sería ruido que esconde los que sí importan. Con `?todos=1` se ven todos.
- **Un código no vigente cuenta como faltante.** Es tan inservible como uno
  vacío, y esa diferencia no se ve mirando si la columna está llena.
- **Ordenado por lo que más falta**, que es donde está el trabajo.

Permiso: `fiscal.completeness`. Lo reciben superadministrador y administrador —
el informe enseña datos fiscales de todos los clientes de la empresa.

```bash
php artisan db:seed --class=FiscalCompletenessPermissionSeeder
```

## Los municipios se piden por departamento

Son 1.122. Cargarlos todos en cada formulario es medio megabyte de HTML y un
desplegable inmanejable en móvil, así que el desplegable de municipio se rellena
por AJAX (`fiscal.municipios`) cuando se elige el departamento.

Ese endpoint solo exige estar autenticado: son códigos públicos de la DIAN, los
mismos para todo el mundo, y no dicen nada de ninguna empresa.

## Los servicios

`services` recibió `product_code`, `product_code_type`, `unit_measure_code` y
`tax_code`. La DIAN admite el estándar UNSPSC o un código interno, pero hay que
**decir cuál** se está usando: de eso se encarga `product_code_type`.

## Qué queda para las fases siguientes

- **Fase 9** — la decisión de si una factura es electrónica, con el grupo de
  afinidad congelado en la factura.
- **Fase 10** — el documento electrónico: XML, CUFE, firma, resoluciones. Ahí es
  donde estos códigos empiezan a viajar de verdad.
