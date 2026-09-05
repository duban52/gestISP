# Habilitación y producción gradual (fase 12)

Cómo se pasa de «el sistema sabe emitir» a «esta empresa está emitiendo
de verdad», sin que el paso se dé a ciegas.

---

## El problema que cierra

Hasta ahora la única forma de saber si una empresa podía emitir
electrónicamente era **intentarlo**. Y ese es justamente el momento en
que no se puede fallar: un consecutivo autorizado gastado en un
documento que la DIAN rechaza deja un hueco que hay que justificar ante
ella.

La respuesta estaba repartida en seis sitios —el informe de completitud
sabía de los datos fiscales, el certificado sabía de su vigencia, la
resolución de la suya, el rango de sus números— y nadie los juntaba.

Peor: **tres comprobaciones estaban escritas y no las llamaba nadie**.
`DianCertificate::diasParaCaducar()`, `NumberingRange::porAgotarse()` y
`DianResolution::vigente()` existían desde la fase 10 sin usarse.

## El diagnóstico

```bash
php artisan dian:diagnostico
php artisan dian:diagnostico --empresa=3
```

Junta las siete comprobaciones y dice, una por una, qué pasa:

| | Comprobación |
|---|---|
| 1 | Datos fiscales de la empresa *(reutiliza el informe de completitud)* |
| 2 | Configuración DIAN: identificador del software y PIN |
| 3 | Certificado digital vigente |
| 4 | Resolución de numeración en vigencia |
| 5 | Rango autorizado con números disponibles |
| 6 | Habilitación aprobada por la DIAN |
| 7 | URL del servicio configurada |

Devuelve **código de salida 1** si algo falta, para poder encadenarlo en
un despliegue: *no sigas si la empresa no está lista*.

### Bloqueante no es lo mismo que pendiente

| | Ejemplo | ¿Impide emitir? |
|---|---|---|
| 🔴 Bloqueante | Certificado **caducado** | Sí |
| 🟡 Aviso | Certificado que caduca **en 20 días** | No, pero hay que verlo |

Mezclarlas hace que los avisos de verdad se pierdan entre los que no lo
son. El umbral de aviso son **30 días**, porque renovar un certificado
ante una entidad acreditada no es inmediato: si el aviso llega el día
antes, ya es tarde.

## El interruptor

```bash
php artisan dian:habilitar --empresa=3
```

Es el más delicado del sistema. A partir de accionarlo, las facturas de
los grupos de afinidad electrónicos dejan de ser documentos internos y
pasan a gastar consecutivos autorizados. **Eso no se deshace.**

Por eso:

- **Pregunta antes al diagnóstico.** Si falta algo bloqueante, no
  enciende nada y dice qué falta.
- **Pide confirmación**, explicando lo que va a pasar.
- **Queda en la trazabilidad**: quién lo encendió y cuándo. Es de las
  cosas que un día habrá que poder explicar.

`--forzar` existe para cuando el diagnóstico se equivoque, pero **avisa
de lo que se está saltando** y lo deja registrado. No es un atajo: es
una decisión.

### Apagar

```bash
php artisan dian:habilitar --empresa=3 --apagar
```

Vuelve a pruebas. **No borra `enabled_at`**: la habilitación ante la
DIAN se consiguió y eso es un hecho histórico; lo que se apaga es la
emisión.

Las facturas ya emitidas **no cambian**: su tipo quedó congelado al
emitirlas.

## Las alertas

```bash
php artisan dian:alertas
```

Vigila las dos cosas que caducan sin avisar y paran la facturación de
golpe:

- **El certificado.** Si nadie mira la fecha, se descubre el día que
  deja de firmar.
- **El rango.** Se agota a mitad de una corrida mensual, y a partir de
  ahí no se emite ni una factura más hasta que la DIAN autorice el
  siguiente.

Solo mira empresas que **ya emiten**: avisar a quien no ha encendido
nada sería ruido. Deja el aviso también en el log, porque el cron corre
de madrugada y nadie lee su salida.

**Ya está en el planificador**, a las 7:00, junto con el barrido horario
de documentos sin transmitir.

## El set de pruebas

```bash
php artisan dian:set-de-pruebas --empresa=3 --dry-run
php artisan dian:set-de-pruebas --empresa=3
```

Es el trámite con el que la DIAN comprueba que su software emite bien
antes de dejarle emitir de verdad.

### Es otra conversación, no el envío de cada día

| | Envío diario | Set de pruebas |
|---|---|---|
| Operación | `SendBillSync` | `SendTestSetAsync` |
| Cuántos documentos | Uno | **Varios, en un mismo ZIP** |
| Qué contesta | Aceptado o rechazado | Una **`ZipKey`** de acuse |
| Cuándo se sabe | En el momento | Después, consultando |

Por eso tiene su propia interfaz (`DianTestSetTransport`) y no un método
más en `DianTransport`: meterlo ahí obligaría a todo lo que sepa
transmitir a saber también de habilitación, que es algo que se hace una
vez en la vida de una empresa.

### Recibido no es aprobado

El comando **no habilita a nadie**. La DIAN devuelve el acuse y valida
después; encender la producción sigue siendo un acto aparte, con su
guardián.

### Qué documentos manda

Los documentos electrónicos ya generados y **firmados** que la DIAN
todavía no ha aceptado. **No los inventa**: generar facturas sintéticas
dentro de una base de producción es exactamente la clase de cosa que no
debe hacer un comando por su cuenta.

Qué mezcla concreta exige la DIAN —con descuento, con varios impuestos,
etc.— sale del set que ella asigna, y hay que producirla emitiendo en el
ambiente de pruebas. Eso sigue siendo manual (ver
[Pendientes](Pendientes.md)).

## El camino completo, de cero a producción

```
1. Completar datos fiscales de la empresa      → informe de completitud
2. Registrar configuración DIAN, certificado,
   resolución y rango                          → panel DIAN
3. php artisan dian:diagnostico                → ¿qué falta?
4. Emitir facturas en ambiente de pruebas      → produce los documentos
5. php artisan dian:set-de-pruebas             → se los manda a la DIAN
6. (la DIAN aprueba la habilitación)
7. php artisan dian:habilitar                  → el interruptor
8. php artisan dian:alertas                    → ya en el planificador
```

Lo único del paso 2 que no se resuelve dentro de gestISP es el
**certificado**: hay que comprárselo a una entidad acreditada por la
ONAC y exigirlo en archivo `.p12`. Ver
[Certificado-Digital.md](Certificado-Digital.md) — incluye qué pedir y
por qué el `.crt` que suelen entregar no sirve.

Mientras llega, `php artisan dian:certificado-de-pruebas` genera uno
autofirmado que permite recorrer los pasos 3 a 5 y ver el flujo entero
funcionando. La DIAN no lo acepta, y el diagnóstico lo dice.

## Producción gradual

El control fino ya existía y no hacía falta inventarlo: **el grupo de
afinidad**. Cada contrato pertenece a uno, y solo los grupos marcados
como electrónicos emiten a la DIAN.

Así el encendido puede ser progresivo de verdad — un grupo primero, el
resto después — sin tocar código ni configuración: se cambia el grupo de
los contratos que se quieran mover.

## Dónde está cada cosa

| Pieza | Archivo |
|---|---|
| El diagnóstico | `app/Billing/Dian/DianReadiness.php` |
| `dian:diagnostico` | `app/Console/Commands/DianDiagnostic.php` |
| `dian:habilitar` | `app/Console/Commands/EnableDianProduction.php` |
| `dian:alertas` | `app/Console/Commands/CheckDianAlerts.php` |
| `dian:set-de-pruebas` | `app/Console/Commands/SendDianTestSet.php` |
| `dian:certificado-de-pruebas` | `app/Console/Commands/GenerateTestCertificate.php` |
| El transporte del set | `app/Billing/Dian/Transport/DianTestSetTransport.php` |

Pruebas: `tests/Feature/Billing/DianReadinessTest.php` (24) y
`tests/Feature/Billing/TestCertificateTest.php` (10).
