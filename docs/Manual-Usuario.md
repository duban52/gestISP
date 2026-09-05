# Antes de empezar

## Qué es gestISP

gestISP es el sistema con el que un proveedor de internet lleva su operación completa: los clientes y sus contratos, la facturación mensual y el recaudo, el almacén, las órdenes de los técnicos, los equipos de red y la documentación de la planta de fibra.

No es un programa de contabilidad ni un sistema de monitoreo de red. Es el sitio donde se cruzan las dos cosas: quién tiene contratado qué, si está al día, y si su conexión está funcionando.

## Las tres ideas que hay que entender primero

Casi todo lo que confunde al principio se aclara con estas tres.

### Empresa y sucursal no son lo mismo

La **empresa** es el contribuyente: un NIT. Es quien factura, quien tiene la resolución de la DIAN y el certificado digital.

La **sucursal** es una sede de esa empresa. Tiene su propia caja, su propio almacén, sus propios técnicos y sus propios clientes.

Una empresa puede tener varias sucursales. Y en una misma instalación de gestISP pueden convivir varias empresas distintas, cada una con sus sucursales.

Esto importa porque decide dónde se configura cada cosa:

| Se configura en la EMPRESA | Se configura en la SUCURSAL |
|---|---|
| Datos fiscales y NIT | Dirección y teléfonos de la sede |
| Resoluciones de la DIAN | Caja y puntos de cobro |
| Certificado digital | Almacén y su inventario |
| Rangos de numeración | Precios de traslado y reconexión |
| Grupos de afinidad | Planes y servicios |

### El contexto de trabajo

Cuando usted entra al sistema, elige **desde qué sucursal va a trabajar**. Eso es el contexto.

Todo lo que vea a partir de ese momento —clientes, contratos, facturas, materiales— es de esa sucursal. No es un filtro que usted pueda quitar: es el alcance de su sesión.

Si su usuario tiene acceso a varias sucursales, puede cambiar de una a otra desde el menú superior, sin cerrar sesión. Al cambiar, la pantalla se recarga con los datos de la nueva sede.

> Si algo "no aparece" —un cliente que usted sabe que existe, un material que estaba ayer— lo primero que hay que mirar es en qué sucursal está parado.

### Rol y permisos

El **rol** dice qué puede hacer usted. gestISP trae cuatro de fábrica, y se pueden crear más.

Un usuario puede tener **roles distintos en sucursales distintas**: administrador en la sede principal y auxiliar en otra. El rol activo se ve en el menú superior, junto a la sucursal.

---

# Los roles

## Qué puede hacer cada uno

| | Superadministrador | Administrador | Auxiliar administrativo | Técnico |
|---|---|---|---|---|
| Panel principal | Sí | Sí | Sí | Sí |
| Empresas y sucursales | Todo | No | No | No |
| Usuarios y roles | Todo | No | No | No |
| Trazabilidad del sistema | Sí | No | No | No |
| Copias de seguridad | Sí | No | No | No |
| Configuración DIAN | Sí | No | No | No |
| Clientes | Todo | Crear y editar | Crear y editar | No |
| Contratos | Todo | Crear y editar | Crear y editar | No |
| Facturación | Todo | Todo | Emitir y consultar | No |
| Notas crédito y débito | Todo | No | No | No |
| Pagos y cajas | Todo | Todo | Cobrar y cuadrar | No |
| Retenciones | Sí | Sí | No | No |
| Almacén | Todo | Todo | Consultar y mover | Consultar |
| Órdenes técnicas | Todo | Todo | Crear y procesar | Solo las suyas |
| Red (OLT, ONT, PPPoE) | Todo | Todo | No | No |
| Planta de fibra | Todo | Todo | No | No |
| Informes gerenciales | Sí | Sí | No | No |

## Cómo leer esta tabla

**Superadministrador.** Todo. Es el único que administra empresas, usuarios, roles, la configuración de la DIAN, la trazabilidad y las copias de seguridad. Debería haber pocos.

**Administrador.** Lleva la operación de su sucursal completa: clientes, facturación, recaudo, almacén, técnicos y red. Lo que no puede es tocar la estructura del sistema ni los datos fiscales de la empresa.

**Auxiliar administrativo.** El mostrador. Atiende clientes, arma contratos, emite y cobra facturas, abre y cierra caja, y despacha órdenes técnicas. No entra a la red ni a los informes.

**Técnico.** Ve **solo las órdenes que le asignaron**, las procesa y consulta material. No ve clientes, ni facturas, ni el resto del sistema.

> Los permisos se pueden ajustar uno a uno desde **Roles**. La tabla de arriba es cómo vienen de fábrica, no una camisa de fuerza.

---

# Entrar al sistema

## El acceso

1. Escriba su correo y su contraseña.
2. El sistema comprueba las credenciales **antes** de preguntar nada más.
3. Si tiene acceso a más de una sucursal o más de un rol, aparece la pantalla de contexto: elija desde dónde va a trabajar.
4. Entra al panel principal.

Si se equivoca de contraseña varias veces, los intentos quedan registrados. No hay bloqueo automático, pero el superadministrador los ve.

## Cambiar de sucursal

En el menú superior, junto a su nombre, aparece la sucursal y el rol activos. Al pulsar allí puede cambiar de sede sin cerrar sesión.

## Su perfil

En **Perfil** puede cambiar su nombre, su teléfono, su foto y su contraseña. No hace falta ningún permiso: cada quien edita el suyo.

Si no sube foto, el sistema genera un avatar con sus iniciales.

## Modo oscuro

El interruptor está en el menú superior. La preferencia se guarda en su usuario, así que se mantiene aunque cierre sesión o entre desde otro equipo.

## Cierre por inactividad

Tras **15 minutos** sin actividad la sesión se cierra sola. Es deliberado: un equipo de mostrador desatendido con la sesión abierta es acceso a los datos de todos los clientes.

---

# Clientes

## Qué es un cliente

La persona o empresa que contrata. Un cliente puede tener **varios contratos** —dos casas, un local— y cada contrato es un servicio independiente con su propia facturación.

## Crear un cliente

Menú **Clientes → Nuevo**.

Los datos mínimos son nombre, apellido, tipo y número de documento, teléfono y correo.

### El tipo de documento importa

Si la empresa va a facturar electrónicamente, el tipo de documento del cliente **debe estar diligenciado**: la DIAN lo exige en el XML de la factura.

Este campo se puede fijar **una sola vez**. Una vez guardado, queda bloqueado, porque cambiar la identificación de un cliente que ya tiene facturas emitidas dejaría documentos apuntando a alguien distinto del que se declaró.

Si el cliente es antiguo y nació sin este dato, la pantalla de edición se lo deja completar. Después ya no.

### Responsabilidades fiscales

En la ficha del cliente se marcan sus responsabilidades fiscales (autorretenedor, gran contribuyente, régimen simple, etc.). Salen del catálogo de la DIAN y viajan en el XML de la factura.

## Buscar clientes

El buscador consulta por nombre, documento, teléfono, correo y número de contrato. También hay un listado completo con filtros y exportación a Excel.

## Importar clientes desde Excel

Menú **Clientes → Importar**.

Sirve para arrancar: trae clientes y contratos de un archivo CSV o Excel, con la opción de traer **saldos pendientes** de un sistema anterior.

El proceso muestra primero una vista previa con los errores detectados. Nada se guarda hasta que usted confirme.

---

# Contratos

## Qué es un contrato

El servicio contratado en una dirección concreta. Es la pieza central del sistema: de él cuelgan las facturas, la conexión de red, las órdenes técnicas y la ubicación.

## Crear un contrato

Un contrato **siempre nace de un cliente**. Desde la ficha del cliente, botón **Nuevo contrato**.

Datos que pide:

- **Plan.** Decide la velocidad y el precio mensual.
- **Servicios.** Lo que se factura cada mes. Puede haber más de uno.
- **Dirección, barrio y municipio.** Donde está instalado.
- **Estrato.** Importante: decide si el internet lleva IVA (ver más abajo).
- **Grupo de afinidad.** Decide si su factura es electrónica o interna.
- **Datos de conexión.** Usuario y contraseña PPPoE, serial de la ONT, puerto NAP.
- **Cláusula de permanencia**, si aplica.

### El número de contrato

Se asigna solo, con un consecutivo **por sucursal**. El prefijo se puede configurar por sede.

### El aviso del estrato

Al guardar, si el contrato es de **estrato 1, 2 o 3** y algún servicio está marcado como gravado con IVA, aparece un aviso:

> El internet residencial de estratos 1, 2 y 3 está excluido de IVA. Revise la clasificación fiscal de los servicios de este contrato.

Es un **aviso, no un bloqueo**. El sistema no sabe si el servicio es internet residencial o televisión, y decidir por usted sería peor que avisarle.

## La ficha del contrato

Al abrir un contrato encontrará cuatro pestañas:

| Pestaña | Qué muestra |
|---|---|
| Facturas | Todas las del contrato, con su estado y saldo |
| Pagos | Lo que se ha recibido |
| Órdenes técnicas | Instalaciones, traslados, soportes |
| Cambios | Qué se le modificó al contrato, quién y cuándo |

Además hay **comentarios internos**: notas del personal sobre el cliente que no salen en ningún documento.

## Diagnóstico de la conexión

Botón **Diagnóstico** en la ficha. Consulta en vivo:

- Si la cuenta PPPoE está conectada y desde cuándo.
- El estado de la ONT y su potencia óptica.
- Qué puerto de qué OLT la atiende.

Sirve para atender una llamada de "no tengo internet" sin salir de la ficha.

## Georreferenciación

Botón **Ubicación**. Guarda la coordenada de la vivienda y muestra las cajas NAP cercanas con puertos libres.

La ubicación puede venir de dos sitios: la que se registra aquí a mano, o la que captura el técnico al cerrar la orden de instalación. El sistema distingue una de otra y muestra un semáforo de confianza que tiene en cuenta la precisión del GPS.

## Listado de contratos

El listado permite filtros acumulables (estado, plan, sucursal, grupo, zona) y **elegir qué columnas ver**. La exportación a Excel sale tal como usted dejó el listado: mismos filtros, mismas columnas.

---

# Servicios y planes

## La diferencia

- Un **servicio** es un concepto facturable: "Internet 100 Mb", "Arriendo de router", "Instalación". Tiene precio, IVA y clasificación fiscal.
- Un **plan** agrupa servicios y es lo que se le asigna al contrato.

## Clasificación fiscal de un servicio

Cada servicio se marca como una de tres:

| Clasificación | Qué significa | En la factura electrónica |
|---|---|---|
| **Gravado** | Lleva IVA a la tarifa que se indique | Lleva el bloque de impuestos con su tarifa |
| **Excluido** | La ley lo deja fuera del IVA | **No lleva bloque de impuestos** |
| **Exento** | Está gravado a tarifa cero | Lleva el bloque de impuestos, en ceros |

Excluido y exento **no son lo mismo** aunque los dos cobren cero de IVA, y en el XML se representan distinto. Marcar mal uno hace que la DIAN rechace el documento.

Para un ISP la regla práctica es:

- Internet residencial de **estratos 1, 2 y 3** → **excluido**.
- El resto → **gravado** a la tarifa general.
- Televisión y otros servicios → según su propia regla, normalmente gravado.

> **Revise sus servicios.** Al activarse esta función, los servicios existentes se clasificaron adivinando por la tarifa que tenían: los que estaban en 0% quedaron como excluidos. Si tiene un servicio de televisión al 0%, seguramente quedó mal clasificado.

## Datos fiscales del servicio

Para facturar electrónicamente cada servicio necesita además:

- **Código del producto** y su tipo (normalmente UNSPSC).
- **Unidad de medida**.
- **Código de impuesto**.

Estos campos solo son obligatorios si la empresa emite factura electrónica. El informe de completitud fiscal le dirá cuáles faltan.

---

# Grupos de afinidad

## Para qué sirven

Un grupo de afinidad clasifica contratos y **decide por qué camino sale su factura**: electrónica a la DIAN, o interna.

Es lo que permite encender la facturación electrónica **poco a poco**: se crea un grupo marcado como electrónico, se le pasan unos cuantos contratos, y esos son los únicos que empiezan a reportarse. El resto sigue igual.

## Cómo se usa

Menú **Grupos de afinidad**. Cada grupo tiene un nombre y una marca de "electrónico" o no. Uno de ellos puede quedar como **predeterminado**: es el que reciben los contratos nuevos.

Desde el listado se puede marcar el predeterminado sin entrar a editar, que es la operación más frecuente.

> La decisión se **congela en la factura** al emitirla. Si mañana el contrato cambia de grupo, las facturas ya emitidas siguen siendo lo que eran. Una factura no cambia de naturaleza después de emitida.

---

# Facturación

## Cómo se factura el mes

Menú **Facturas → Generar**.

La corrida mensual recorre los contratos activos de la sucursal y emite una factura por cada uno con:

- Los servicios de su plan.
- Los cargos adicionales pendientes.
- El saldo anterior, si lo hay.

Cada corrida queda registrada: qué día se hizo, cuántas facturas salieron, por cuánto, y quién la lanzó.

## El detalle de una corrida

Menú **Facturas → Corridas**. Al abrir una corrida se ve qué se facturó y a quién, con las descargas del lote en PDF.

## Numeración: dos series que no se mezclan

Aquí hay algo que conviene entender.

- Una factura **electrónica** toma su número del **rango autorizado** por la resolución de la DIAN. Es el único número legítimo que puede llevar.
- Una factura **interna** toma su número de una serie propia de la sucursal, que no consume ningún consecutivo autorizado.

Son dos tablas distintas a propósito: así es **imposible** que un documento interno gaste un consecutivo de la DIAN.

Si no hay rango autorizado vigente, la factura electrónica **no se emite** y el sistema lo dice. No se inventa un número ni cae a la serie interna.

## Anular una factura

Las facturas **nunca se borran**: cambian a estado *Anulada*. El consecutivo queda gastado, que es como debe ser: un hueco en la numeración hay que poder explicarlo.

## Cargos adicionales

Conceptos que se suman a la próxima factura del contrato: una reconexión, un traslado, un equipo. Se pueden cargar de una vez o en cuotas.

## PDF de facturas

- **Una factura:** botón de descarga en su ficha.
- **El lote del mes:** genera un PDF con todas las pendientes de la sucursal. Se procesa en segundo plano y avisa cuando está listo.

Si la factura es electrónica, su PDF lleva además el **CUFE**, el **código QR** para consultarla en el portal de la DIAN, y los datos de la resolución que autorizó el rango.

---

# Notas crédito y débito

## Para qué sirven

Corrigen una factura ya emitida **sin modificarla**. Una factura emitida no se toca: se corrige con una nota.

- **Nota crédito:** disminuye lo que el cliente debe (un descuento aplicado después, un cobro de más, una anulación parcial).
- **Nota débito:** aumenta lo que debe (un cargo que faltó, un interés).

## Cómo se emite

Menú **Notas → Nueva**. Se elige la factura a corregir, el motivo y los conceptos.

Al guardar, la nota ajusta el saldo de la factura automáticamente.

## Si la factura era electrónica

La nota también es electrónica y se reporta a la DIAN, con su propio código de identificación (el **CUDE**) y una referencia a la factura que corrige.

> Las notas llevan **su propia numeración**, no salen de un rango autorizado. Es lo que dice el anexo técnico de la DIAN y lo que se ve en sus propios ejemplos.

---

# Pagos, caja y recaudo

## Abrir y cerrar caja

Antes de cobrar hay que **abrir la caja** con su base. Al terminar el turno se cierra declarando lo contado, y el sistema muestra la diferencia contra lo que debería haber.

## Registrar un pago

Menú **Pagos → Nuevo**, o desde la ficha de la factura.

Se indica la factura, el valor y la forma de pago. Si el pago cubre menos del total, la factura queda con saldo. Si cubre de más, la diferencia queda como **saldo a favor** del contrato y se aplica sola en la siguiente factura.

## Cobro múltiple

Cuando un cliente paga varias facturas de varios contratos en una sola entrega de dinero.

Es **todo o nada**: si una de las facturas falla, no se registra ninguna. Un cobro a medias con el dinero ya recibido es el peor de los resultados.

## Retenciones

Si el cliente practica retenciones al pagar (renta, IVA, ICA), se registran junto con el pago.

Punto importante: **la retención salda la factura pero no entra a la caja**. El dinero que se recibió es menor que lo que se saldó, y el cuadre de caja lo refleja así.

El reporte de retenciones (menú **Retenciones**) es el insumo para descontarlas en la declaración.

## Recibo de caja

Cada cobro genera su recibo, imprimible en **tirilla térmica** y descargable en PDF.

Se emite **un recibo por contrato**, aunque el cobro haya sido múltiple: el cliente necesita un comprobante por cada servicio.

## Anticipos y saldo a favor

Un cliente puede pagar por adelantado. El anticipo queda como saldo a favor del contrato y se aplica automáticamente a las facturas siguientes.

## Resumen de cajas

Menú **Cajas → Resumen**. Cuadre por período entre todos los puntos de cobro de la sucursal.

---

# Facturación electrónica (DIAN)

Este módulo es solo para el **superadministrador**.

## Qué hay que tener

Para emitir factura electrónica hacen falta cinco cosas. El panel las revisa todas.

| | Qué es | De dónde sale |
|---|---|---|
| 1 | Datos fiscales completos de la empresa | Se diligencian en la ficha de la empresa |
| 2 | Configuración del software | La da la DIAN al registrar el software |
| 3 | Certificado digital | Se le compra a una entidad acreditada por la ONAC |
| 4 | Resolución de facturación | La expide la DIAN, gratis, en su portal |
| 5 | Rango de numeración | Viene dentro de la resolución |

## El panel DIAN

Menú **DIAN**. Lleva a la empresa en la que usted está trabajando.

Arriba aparece **"Qué falta para poder emitir"**: la lista de comprobaciones, cada una en verde, amarillo o rojo.

- **Rojo:** impide emitir.
- **Amarillo:** no impide hoy, pero hay que verlo (un certificado que caduca en 20 días).
- **Verde:** listo.

### Configuración del software

Identificador del software, PIN y —si está en habilitación— el identificador del set de pruebas.

El PIN **nunca se devuelve** al formulario: dejarlo en blanco significa "no lo cambies", no "bórralo".

### Dirección del servicio de la DIAN

Viene puesta y normalmente no hay que tocarla. El campo existe para dos casos: que la DIAN cambie la dirección, o que la empresa emita a través de un proveedor tecnológico.

### El certificado

Se sube el archivo `.p12` con su contraseña. El sistema comprueba que **abra** antes de guardarlo: descubrir que la contraseña estaba mal el día de firmar es descubrirlo a mitad de la corrida mensual.

Reglas del certificado:

- Se guarda fuera del directorio público y **no se puede descargar**.
- La contraseña se guarda cifrada y no se devuelve nunca.
- Las fechas de vigencia se leen del propio archivo.
- Solo uno puede estar activo.
- Al desactivarlo, el archivo **no se borra**: con él se firmaron documentos ya transmitidos.

> **El `.crt` no sirve.** Si la entidad certificadora le entregó un archivo `.crt`, ese es solo la mitad pública y no permite firmar. Pida el certificado en archivo **`.p12` o `.pfx`**, no en la nube ni en token de hardware.

### Resoluciones y rangos

Se registra la resolución de la DIAN (número, vigencia, clave técnica) y dentro de ella el rango o rangos de numeración. Un rango se puede asignar a una sucursal concreta o dejarlo para toda la empresa.

## Certificado de pruebas

Mientras llega el certificado de verdad, un técnico puede generar uno de pruebas desde la consola. Sirve para ver todo el flujo funcionando —el XML, la firma, el QR, la transmisión— pero **la DIAN no lo acepta**.

El panel lo marca con una etiqueta roja **Pruebas** y el diagnóstico lo declara bloqueante. Es a propósito: un certificado de pruebas olvidado significa firmar un mes entero con algo que la DIAN rechaza.

## Informe de completitud fiscal

Menú **Completitud fiscal**. Revisa empresa por empresa, cliente por cliente y servicio por servicio qué datos faltan para poder emitir, y enlaza directamente a la pantalla donde se completan.

Se consulta **antes** de que haga falta, no el día de emitir.

## Estados de un documento electrónico

| Estado | Qué significa |
|---|---|
| Borrador | Hubo un problema al armarlo. El motivo queda anotado. |
| Generado | El XML está armado pero sin firmar (no hay certificado) |
| Firmado | Listo para transmitir |
| Enviado | Se mandó a la DIAN, esperando respuesta |
| Aceptado | La DIAN lo validó |
| Rechazado | La DIAN lo devolvió, con el motivo |

Si algo falla al armar el documento, **la factura no se cae**: ya está emitida y el cliente ya tiene el servicio. El fallo se anota y se puede reintentar.

---

# Almacén

## Cómo está organizado

- **Almacén:** el depósito de una sucursal. Cada sede tiene el suyo y **no ve el de las demás**.
- **Categoría:** agrupa materiales (cables, conectores, equipos).
- **Material:** lo que se guarda. Puede llevar número de serie o no.

## Movimientos

Todo lo que entra y sale se registra: entradas por compra, salidas a órdenes técnicas, traslados entre almacenes, ajustes de inventario.

El material con número de serie se controla uno a uno; el resto por cantidad.

## Consultas

- Inventario actual, con exportación a PDF.
- Historial de movimientos, filtrable y exportable a PDF y Excel.
- Consulta de un número de serie: dónde está y por dónde pasó.

---

# Órdenes técnicas

## Tipos

Instalación, traslado, retiro, soporte, cambio de equipo.

## El circuito

1. **Se crea** la orden desde el contrato o desde el módulo, y se asigna a un técnico.
2. **El técnico la ve** en "Mis órdenes". Solo las suyas.
3. **La procesa:** registra lo que hizo, el material que gastó y la firma del cliente.
4. **Se verifica:** un administrativo la revisa y la cierra o la devuelve.

## Al procesar una instalación

- El material es **obligatorio**: una instalación sin material descuadra el almacén.
- Se captura la **firma del cliente**.
- Se puede capturar la ubicación GPS.

Al cerrarla, el material sale del almacén automáticamente.

## Rechazar una orden

El técnico puede rechazar una orden indicando el motivo (nadie en casa, dirección errada, sin acceso). Vuelve al administrativo para reprogramar.

---

# Red

## OLT

Menú **OLT**. Muestra los equipos, su estado en vivo y sus estadísticas.

La ficha de una OLT carga en dos fases: primero lo que está guardado, después lo que se consulta al equipo. Así una OLT apagada solo retrasa su propia fila y no la pantalla entera.

Incluye tarjetas, puertos PON con su ocupación y tráfico, y enlaces de subida.

## ONT

Menú **ONT**. Dos listados: las **autorizadas** y las que están **por autorizar** (equipos vistos por la OLT que todavía no se han dado de alta).

Desde aquí se autoriza una ONT, se mueve de puerto, se activa o desactiva la televisión y se consulta su potencia óptica.

Las potencias se muestran con bandas de color: normal, límite y crítica.

## PPPoE

Menú **PPPoE**. Las cuentas de conexión de los clientes, con su estado (conectada o no), su IP y desde cuándo.

Se pueden crear, editar, importar desde el router y reiniciar sesiones.

La exportación con contraseñas requiere un permiso propio: ese archivo se lleva las claves de todos los clientes fuera del sistema.

## Cortes masivos

Menú **PPPoE → Cortes masivos**. Corta el servicio a varios clientes de una vez, por contrato o por usuario.

Va en **dos pasos**: primero se ve la lista de a quién se va a cortar, y solo después se confirma.

> El corte **no cambia el estado del contrato**. Es una acción sobre la red, no sobre la facturación.

## Routers

Menú **Routers**. Los Mikrotik de la operación, con sus credenciales de acceso para las operaciones de PPPoE.

## Aprovisionamiento sin contrato

Se pueden dar de alta ONT y cuentas PPPoE sueltas, sin contrato asociado. Sirve para pruebas y para equipos de la propia empresa.

---

# Planta de fibra

Este módulo documenta la red física. No la controla: la describe.

## Redes ópticas y zonas

Menú **Redes**. Una red óptica agrupa zonas, y las zonas agrupan cajas NAP.

## Cajas NAP

Menú **Redes → Cajas NAP**. Cada caja tiene un número de puertos y una ubicación en el mapa.

**La ocupación no se guarda: se deduce.** Un puerto está ocupado si hay un contrato apuntándolo. Así no puede quedar un puerto marcado como libre que en realidad tiene un cliente.

## Muflas, empalmes y cables

Menú **Redes → Muflas** y **Cables**.

Se documentan los cables de fibra con sus hilos, las muflas donde se empalman y los splitters.

**Un hilo tiene dos extremos**, y el sistema los trata así. Al marcar un corte en un cable, calcula por simulación qué clientes quedarían sin servicio siguiendo el camino de la fibra.

---

# Informes gerenciales

Menú **Informes**. Cuatro tableros:

| Informe | Responde |
|---|---|
| Crecimiento | Cuántos contratos entran y salen por mes |
| Órdenes técnicas | Cuántas se hacen, de qué tipo, por técnico |
| Facturación y recaudo | Cuánto se factura, cuánto se recauda, cuánto queda pendiente |
| Aprovisionamiento | Ocupación de la red, puertos usados y libres |

> Algunas cifras son **aproximaciones**. Las de alta, baja y resolución de contratos se calculan a partir de columnas que no fueron diseñadas para eso. Sirven para ver tendencias, no para cuadrar contabilidad.

---

# Notificaciones

## A los clientes

El sistema avisa por **correo y WhatsApp**:

- Factura generada.
- Factura por vencer.
- Factura vencida.
- Bienvenida al contratar.
- Orden técnica creada, y cuando se cierra.

Los correos usan una plantilla única con el membrete de la sucursal.

## A los técnicos

Un contador en la barra superior avisa cuando se les asigna una orden nueva.

---

# Usuarios y roles

Solo para el superadministrador.

## Crear un usuario

Menú **Usuarios → Nuevo**. Además de los datos personales, se le asignan **sucursales y un rol en cada una**.

No hay registro público: los usuarios se crean desde aquí.

## Habilitar e inhabilitar

Un usuario se **inhabilita**, no se borra. Un usuario borrado dejaría facturas y movimientos sin autor.

## Sesiones de un usuario

Desde su ficha se ven sus sesiones activas y se pueden **cerrar de forma remota**, una a una o todas. Sirve cuando alguien deja la empresa o pierde un equipo.

## Roles

Menú **Roles**. Se crean roles nuevos y se marcan sus permisos uno a uno.

> El menú se arma según el rol de su sesión: si un permiso no está marcado, la opción no aparece.

---

# Trazabilidad

Menú **Auditoría**. Solo para el superadministrador.

Registra **todo lo que hacen los usuarios**: qué crearon, qué modificaron, qué borraron, con los valores antes y después, la IP, la sucursal y el rol con el que actuaban.

Los datos sensibles —contraseñas, PIN de la DIAN, claves técnicas, comunidades SNMP— se guardan tapados.

No registra la telemetría de los sondeos automáticos de red: son decenas de miles de lecturas por día que taparían lo que de verdad importa.

---

# Copias de seguridad

Menú **Copias de seguridad**. Solo para el superadministrador.

Muestra las copias disponibles y permite descargarlas.

> El archivo que se descarga es **la base de datos entera**: clientes, documentos, contraseñas PPPoE e histórico de pagos. Trátelo como tal.

---

# Preguntas frecuentes

**No veo un cliente que sé que existe.**
Mire en qué sucursal está parado. Los clientes son de una sucursal.

**No me aparece una opción del menú.**
Su rol no tiene ese permiso. El superadministrador puede revisarlo en Roles.

**Una factura salió sin CUFE.**
No es electrónica, o su documento quedó en borrador. Revise el grupo de afinidad del contrato y el panel DIAN.

**El sistema me sacó solo.**
Quince minutos de inactividad cierran la sesión.

**Emití una factura con un error.**
No se modifica: se corrige con una nota crédito o débito, o se anula.

**Cobré de más.**
La diferencia queda como saldo a favor del contrato y se aplica sola en la siguiente factura.

**La caja no cuadra y hubo retenciones.**
Es lo esperado: la retención salda la factura pero no entra dinero a la caja.

**Un técnico no ve una orden que le asigné.**
Compruebe que quedó asignada a él y que su usuario tiene acceso a esa sucursal.
