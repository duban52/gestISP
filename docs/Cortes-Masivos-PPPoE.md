# Cortes masivos de PPPoE

Pantalla: **PPPoE → Cortes masivos** (`/pppoe/cortes`)
Regla de negocio: [`app/Services/PppoeMassCutoff.php`](../app/Services/PppoeMassCutoff.php)
Pruebas: [`tests/Feature/Network/PppoeMassCutoffTest.php`](../tests/Feature/Network/PppoeMassCutoffTest.php)

## Qué hace

Deshabilita el *secret* en el Mikrotik **y tumba la sesión activa**. Sin lo
segundo el corte no se siente: el cliente sigue navegando hasta que reinicie el
equipo.

Va en dos pasos que no se pueden saltar —**revisar** y **ejecutar**— porque un
corte masivo deja a decenas de clientes sin servicio y no tiene botón de
deshacer. Revisar no toca nada: ni la base ni el router.

## Qué se puede pegar en la lista

Tres cosas, mezcladas, y se prueban **en este orden**:

| Orden | Identificador | Ejemplo | De dónde sale |
|---|---|---|---|
| 1 | Número de contrato | `ENG000123` | Campo propio del contrato |
| 2 | Usuario PPPoE | `pepito.perez` | Campo propio de la cuenta |
| 3 | **Documento de identidad** | `71825597` | **Dentro del comentario de la cuenta** |

El orden va de la coincidencia más exacta a la más floja. El documento va último
porque **no es un campo**: se busca dentro del texto libre que la operación
viene anotando en el comentario de la cuenta.

Se admite con separadores o sin ellos — `71825597`, `71.825.597` y `71-825-597`
encuentran la misma cuenta.

En un archivo, se reconocen los encabezados `cedula`, `cc`, `documento`,
`identificacion`, `identidad` y `nit`, además de los de contrato y usuario.

## Por qué el documento no se busca con `LIKE`

Esta es la decisión que sostiene todo lo demás.

Un `WHERE comment LIKE '%1164173%'` encuentra el teléfono `3116417754`, porque
la cédula está **contenida** dentro de él. Bastaría eso para cortarle el
servicio a quien no era.

En vez de eso, el comentario se **parte en números completos** y se comparan
enteros:

- Se toman las tiradas de dígitos y **puntos** —el punto es separador de miles
  en Colombia: `71.825.597` es *un* número—, y todo lo demás corta.
- `CL 18 # 20-59` produce `18`, `20` y `59`, que quedan fuera por cortos. **No**
  produce un `182059` inventado.
- Solo cuentan los de **5 a 11 dígitos**. Un comentario está lleno de números
  cortos —números de casa, pisos, megas del plan— y cualquiera de ellos
  provocaría un corte equivocado.

### Las direcciones IP

Un comentario de PPPoE lleva IPs a menudo, y sin quitarlas `192.168.1.50` se
convertiría en el documento `192168150`.

La comprobación **no** puede ser "cuatro grupos de dígitos separados por
puntos", porque la cédula `1.042.772.330` tiene exactamente esa forma. Se valida
como IP de verdad (`filter_var` con `FILTER_FLAG_IPV4`): como IP no vale —772 y
330 pasan de 255— y así se distingue una de otra.

## Cuando un documento apunta a dos clientes

Que un documento aparezca en **varias cuentas del mismo cliente** es lo normal:
tiene dos contratos. Se cortan las dos y no se advierte nada.

Que aparezca en cuentas de **clientes distintos** es otra cosa: o está mal
anotado en un comentario, o son dos personas. La fila se marca como **ambigua**:

- fondo rojo claro y franja roja en la tabla,
- icono de advertencia en la columna «Se encontró por»,
- y un **aviso arriba del listado** con la cuenta de cuántas hay.

El aviso arriba no es redundante: en una tanda de cientos de filas, un color en
una fila pasa desapercibido.

**La fila sigue siendo cortable.** No se bloquea a propósito: puede ser
legítimo, y el paso de revisión es justamente el mecanismo de control del
módulo. Lo que se garantiza es que no pase inadvertido.

## La columna «Se encontró por»

Cada fila de la revisión dice cómo se resolvió: `Contrato`, `Usuario` o
`Documento`. Importa mirarla antes de ejecutar — las dos primeras son
coincidencias exactas contra un campo; la tercera salió de un texto escrito a
mano.

## Coste de las consultas

Buscar un documento obliga a **barrer comentarios**, que no tienen índice que
valga. Por eso:

1. **Si la lista no trae nada con forma de documento, no se consulta nada.** Una
   tanda normal de números de contrato no paga el barrido. Hay una prueba que lo
   fija.
2. Cuando sí hay documentos, se hacen **dos** consultas: una trae solo `id` y
   `comment` de la sucursal (dos columnas, ningún objeto hidratado), y la
   segunda carga enteras —con contrato, cliente y router— únicamente las que
   coincidieron.
3. Los documentos de **otras sucursales** se buscan en una segunda pasada, una
   sola consulta, y solo si quedó alguna línea con forma de documento sin
   resolver. Sirve para decir *«pertenece a otra sucursal»* en vez de *«no
   corresponde a nada»*, que es lo que lleva a un operador a crear un duplicado.

## Trazabilidad

Se registran tres cosas distintas, y cada una responde una pregunta distinta:

| Registro | Responde |
|---|---|
| `pppoe.cutoff_file_loaded` | ¿De qué archivo salió la lista? |
| `pppoe.mass_cut` | ¿Quién ordenó el corte y a cuántos? |
| `pppoe.cut` (una por cuenta) | ¿A mí por qué me cortaron? |

## Una trampa de PHP que quedó fijada con una prueba

`documentosEnComentario()` usa un array como conjunto para no repetir. PHP
**convierte a entero toda clave que parezca un número**, así que sin un
`strval` explícito el método devolvía `int` donde su firma declara `string` — y
un documento con ceros a la izquierda habría seguido siendo `string`, dejando
los dos tipos mezclados en el mismo array.

Está corregido y hay una prueba con `assertSame` que lo mantiene así.
