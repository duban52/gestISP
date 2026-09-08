# De cero a la primera factura electrónica

Guía para montar el mínimo —servicio, plan, cliente, contrato— y llevar
una factura hasta la DIAN. Se sigue en orden.

Está escrita para la fase de **habilitación**: se emite contra el
ambiente de pruebas de la DIAN, con el rango de pruebas. Nada de esto
gasta consecutivos de producción.

---

# Paso 0 · Confirmar el punto de partida

```bash
cd /var/www/gestisp && php artisan dian:diagnostico --empresa=5
```

Antes de empezar deberían estar en verde: **datos fiscales de la
empresa**, **configuración DIAN**, **certificado**, **resolución**,
**rango** y **servicio de la DIAN**.

La **habilitación** va a estar en rojo, y es lo correcto: dice que el
set de pruebas todavía no está aprobado. Eso es lo que se va a resolver
al final de esta guía.

---

# Paso 1 · Encender la emisión, en pruebas

Es el paso que la gente no encuentra, porque el interruptor de la
empresa es de solo lectura en el panel.

```bash
cd /var/www/gestisp && php artisan dian:habilitar --pruebas --empresa=5
```

Esto deja la empresa emitiendo documentos electrónicos **contra el
ambiente de habilitación**. No anota la habilitación como aprobada
—`enabled_at` sigue vacío— así que no hay forma de que acabe emitiendo
en producción por accidente.

> `dian:habilitar` **sin** `--pruebas` es otra cosa: pasa a producción.
> Eso se hace al final, cuando la DIAN apruebe.

---

# Paso 2 · El grupo de afinidad

Sin esto no sirve nada de lo demás: el grupo es lo que decide, contrato
a contrato, si su factura va a la DIAN.

**Grupos de afinidad → Nuevo**

| Campo | Valor |
|---|---|
| Nombre | `Electrónico` |
| Requiere facturación electrónica | **Sí** |
| Predeterminado | Como prefiera |

Si ya existe uno marcado como electrónico, sáltese el paso.

---

# Paso 3 · El servicio

**Servicios → Nuevo**

Lo obligatorio del formulario es poco; lo que hace falta para la DIAN
es más:

| Campo | Qué poner | Por qué |
|---|---|---|
| Nombre | `Internet 100 Mb` | |
| ¿Dónde vive? | **De la empresa** | El precio es igual en todas las sedes y los datos fiscales son del contribuyente |
| Precio base | `80000` | |
| IVA (%) | `0` | Internet residencial de estratos 1-3 |
| Clasificación fiscal | **Excluido** | Con 0% hay que decir si es excluido o exento: en el XML **no se escriben igual** |
| Código del producto | `81112200` | UNSPSC de servicios de telecomunicaciones |
| Tipo de código | `001` | 001 = UNSPSC |
| Unidad de medida | `94` | 94 = unidad |
| Código de impuesto | `01` | 01 = IVA |

> Si el servicio fuera gravado, ponga el IVA que corresponda y la
> clasificación **Gravado**. Un excluido con tarifa mayor que cero no
> es una cosa que exista.

---

# Paso 4 · El plan

**Planes de servicios → Nuevo**

| Campo | Valor |
|---|---|
| ¿Dónde vive? | **De la empresa** |
| Nombre | `Plan 100 Megas` |
| Se ofrece en contratos nuevos | Sí |
| Servicios | Marque el del paso 3 |

El plan agrupa; lo que se factura son sus **servicios**, una línea por
cada uno.

---

# Paso 5 · El cliente

**Gestión de clientes → Nuevo**

Aquí está la mayoría de los tropiezos. El XML se niega a armarse si
falta cualquiera de estos siete:

| Campo | Ejemplo | Nota |
|---|---|---|
| Número de documento | `1042770586` | |
| Tipo de documento | `13` (cédula) / `31` (NIT) | **Se fija una sola vez.** Después queda bloqueado |
| Tipo de organización | `2` (persona natural) / `1` (jurídica) | |
| Departamento (DANE) | Antioquia | |
| Municipio (DANE) | Medellín | Depende del departamento |
| Dirección fiscal | `Carrera 70 # 30-20` | Puede diferir de la de instalación |
| Correo | `cliente@ejemplo.com` | La DIAN lo exige |

> El **tipo de documento** solo se puede fijar una vez. Si se equivoca,
> hay que corregirlo antes de emitirle nada: cambiarlo después dejaría
> facturas apuntando a una identificación distinta de la declarada.

---

# Paso 6 · El contrato

Desde la ficha del cliente: **Nuevo contrato**.

| Campo | Valor | Por qué importa |
|---|---|---|
| Plan | El del paso 4 | De él salen los servicios y el precio |
| **Grupo de afinidad** | **El electrónico del paso 2** | **Es lo que manda la factura a la DIAN** |
| Estrato | `1`, `2` o `3` | Coherente con la clasificación excluida |
| Dirección, barrio, municipio | Los de la instalación | |
| Estado | Activo | Un contrato suspendido no se factura |

Al guardar puede salir el aviso del estrato. Es un **aviso, no un
error**: comprueba que la clasificación fiscal del servicio cuadre con
el estrato del contrato.

---

# Paso 7 · La primera factura

Desde la ficha del contrato, pestaña **Estado de cuenta**, botón
**Generar factura del mes**.

Pide confirmación porque gasta un consecutivo del rango autorizado —de
pruebas, en este caso— y eso no se recupera.

## Comprobar que salió electrónica

```bash
cd /var/www/gestisp && mysql -u USUARIO -p -D BASE -e "
SELECT i.id, i.full_number, i.document_kind, i.total,
       d.status, d.environment_code, LEFT(d.cufe,24) AS cufe, d.last_error
FROM invoices i
LEFT JOIN electronic_documents d ON d.invoice_id = i.id
ORDER BY i.id DESC LIMIT 3;"
```

Lo que debe verse:

| Columna | Valor esperado | Si no |
|---|---|---|
| `document_kind` | `electronic` | Revise el grupo de afinidad del contrato y el paso 1 |
| `status` | `signed` | Si es `generated`, no encontró el certificado |
| `environment_code` | `2` (pruebas) | |
| `cufe` | 96 caracteres | |
| `last_error` | vacío | **Si trae texto, ahí está el motivo exacto** |

`last_error` es el que responde casi siempre: dice qué dato fiscal
falta y a quién.

## Mirar el XML y el PDF

El PDF de la factura ahora lleva el CUFE real, el código QR y los datos
de la resolución. Descárguelo desde la ficha y compruébelo: si el CUFE
está y el QR se ve, la representación gráfica está bien.

---

# Paso 8 · Contra la DIAN

## Qué documentos pide

La DIAN asigna un **set de pruebas** con una mezcla concreta —con
descuento, con varios impuestos, exento…—. Hay que producir esa mezcla
emitiendo facturas en este ambiente, repitiendo los pasos 5 a 7 con las
variantes que su set exija.

Esto no se automatizó a propósito: generar facturas sintéticas dentro
de una base real es de las cosas que no debe hacer un comando por su
cuenta.

## Mandarlo

```bash
cd /var/www/gestisp && php artisan dian:set-de-pruebas --empresa=5
```

Manda los documentos **firmados** que existan, en un ZIP, al ambiente
de habilitación. No inventa nada: si no hay documentos en estado
`signed`, no manda nada.

## Qué esperar

Que el primer intento rebote es normal, y es información. Quedan dos
incógnitas que solo se resuelven hablando con la DIAN de verdad:

- Los **valores exactos de WS-Security**. La guía de consumo los
  muestra en una imagen que no se puede leer del PDF, así que
  `XmlSecuritySigner` usa los estándar del perfil.
- Si la DIAN acepta la **estructura del sobre** tal como se reprodujo.

**Guarde la respuesta completa**, aunque sea un error largo: el mensaje
es lo que dice qué ajustar.

## Cuando la DIAN apruebe

```bash
cd /var/www/gestisp && php artisan dian:habilitar --empresa=5
```

Ese sí pasa a producción y anota la habilitación. A partir de ahí los
contratos de grupos electrónicos gastan consecutivos de verdad.

---

# Si algo no sale

| Síntoma | Causa habitual |
|---|---|
| La factura sale `internal` | El contrato no está en un grupo electrónico, o falta el paso 1 |
| El documento queda en `generated` | No hay certificado vigente para esa empresa |
| El documento queda en `draft` | Lea `last_error`: casi siempre son datos fiscales del cliente |
| «No hay ningún rango autorizado» | Falta registrar la resolución o su rango en el panel |
| «Este contrato ya tiene factura del período» | Ya se emitió este mes. Una segunda gastaría otro consecutivo |
| El documento no se transmite | Falta el worker de la cola, o `DIAN_TRANSPORT=fake` |

Y el que resuelve el resto:

```bash
cd /var/www/gestisp && php artisan dian:diagnostico --empresa=5
```
