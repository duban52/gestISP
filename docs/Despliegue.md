# Despliegue a producción: fases 0 a 12

Este documento es un **runbook**: se sigue en orden, de arriba abajo. No
es una referencia para consultar salteado.

Cubre el salto desde una producción anterior al concepto de empresa
hasta el estado actual: multiempresa, numeración unificada, datos
fiscales y facturación electrónica DIAN.

## Qué se va a aplicar

- **10 commits**, de `2a91e9d` (fase 5) a `e089eff`.
- **12 migraciones nuevas** en esta rama, más las de las fases 0 a 5 si
  producción todavía no las tiene.
- **Ninguna dependencia nueva.** `composer.json` no cambia.
- Tres variables de entorno nuevas, todas opcionales.

## Lo que puede salir mal

Cuatro cosas, y las cuatro se previenen. Están resueltas en los pasos de
abajo, pero conviene tenerlas presentes desde el principio:

| Riesgo | Consecuencia | Se previene en |
|---|---|---|
| `audits` es enorme | La migración tarda horas y bloquea la tabla | Paso 2.1 |
| NIT escritos distinto en sucursales | Un contribuyente se parte en varias empresas | Paso 2.2 |
| Alguna sucursal sin NIT | La migración **falla** a mitad | Paso 2.2 |
| Servicios al 0% mal clasificados | Se emite IVA excluido donde no toca | Paso 4.4 |

---

# Paso 0 · Averiguar dónde está producción

**No dé por hecho en qué punto está.** Todo lo demás depende de esto.

En el servidor:

```bash
cd /var/www/gestisp
php artisan migrate:status | tail -40
```

Y directamente contra la base, que es lo que no miente:

```sql
SELECT COUNT(*) AS aplicadas FROM migrations;
SELECT migration FROM migrations ORDER BY id DESC LIMIT 10;

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'companies')       AS tiene_companies,
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'affinity_groups') AS tiene_grupos,
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'electronic_documents') AS tiene_dian;
```

Interprete así:

| Resultado | Dónde está |
|---|---|
| Sin `companies` | Antes de la fase 0. Se aplica **todo**. |
| Con `companies`, sin `affinity_groups` | A mitad de las fases 0-5. Raro; revise antes de seguir. |
| Con `affinity_groups`, sin `electronic_documents` | En la fase 5. Se aplican las 12 nuevas. |
| Con `electronic_documents` | Ya tiene parte de esto. **Pare y revise** qué falta exactamente. |

Anote el resultado. Lo va a necesitar en el paso 3.

---

# Paso 1 · Ensayo sobre una copia

**Este es el paso que de verdad evita el desastre.** Nada de lo que
sigue debería ejecutarse por primera vez sobre producción.

## 1.1 Traer una copia real

En el servidor:

```bash
mysqldump -u USUARIO -p --single-transaction --routines BASE > /tmp/prod-ensayo.sql
gzip /tmp/prod-ensayo.sql
```

`--single-transaction` evita bloquear las tablas mientras se vuelca.

Descárguelo a su máquina y restáurelo en una base aparte:

```bash
mysql -u root -e "CREATE DATABASE gestisp_ensayo CHARACTER SET utf8mb4"
gunzip < prod-ensayo.sql.gz | mysql -u root gestisp_ensayo
```

## 1.2 Correr la migración contra esa copia

Apunte un `.env` a `gestisp_ensayo` y ejecute exactamente la secuencia
del paso 3, **midiendo el tiempo**:

```bash
time php artisan migrate --force
```

Ese tiempo es el que va a durar la ventana de mantenimiento en
producción. Si sale de horas, vuelva al paso 2.1.

## 1.3 Revisar el resultado

```bash
php artisan gestisp:empresas-migrar
php artisan dian:diagnostico
```

Y navegue por la aplicación con esa base: entre, cambie de sucursal,
abra un contrato, genere una factura de prueba.

> Si algo va a fallar, falla aquí. Repita este paso las veces que haga
> falta: restaurar la copia y volver a empezar cuesta minutos.

---

# Paso 2 · Preparar producción

Estos dos pasos se hacen **con el sistema funcionando**, días antes si
quiere. No requieren parada.

## 2.1 Recortar `audits`

La migración `add_company_to_tenant_tables` añade una columna, una clave
foránea y un índice a **26 tablas**, y `audits` es una de ellas.

Mire cuánto pesa:

```sql
SELECT table_name,
       ROUND(data_length/1024/1024/1024, 2) AS datos_gb,
       ROUND(index_length/1024/1024/1024, 2) AS indices_gb,
       table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'audits';
```

Si pasa de 1 GB, recórtela antes. Primero mirando, sin borrar:

```bash
php artisan audits:prune --days=365 --dry-run
```

Y cuando esté conforme con la cifra:

```bash
php artisan audits:prune --days=365
```

Después, para que el espacio se libere de verdad:

```sql
OPTIMIZE TABLE audits;
```

> Esto convierte la migración más lenta del despliegue en una rápida. Es
> el paso con mejor relación entre esfuerzo y riesgo evitado.

## 2.2 Revisar los NIT de las sucursales

La migración crea **una empresa por cada NIT distinto** que encuentre en
`branches`. De ahí salen dos problemas.

**El primero: el mismo NIT escrito de formas distintas.**

```sql
SELECT id, name, nit FROM branches ORDER BY nit, id;
```

Mírelo con calma. Si ve `900123456`, `900.123.456` y `900123456-7`, eso
es **un solo contribuyente** que la migración va a partir en tres
empresas. Normalícelo antes:

```sql
-- Revise SIEMPRE con SELECT antes de actualizar
UPDATE branches SET nit = '900123456' WHERE id IN (3, 7);
```

**El segundo: sucursales sin NIT.**

```sql
SELECT id, name, nit FROM branches WHERE nit IS NULL OR TRIM(nit) = '';
```

Si hay alguna, la migración no falla —le pone un marcador
`SIN-NIT-<id>`— pero le deja una empresa mal creada que habrá que
corregir a mano. Es mejor poner el NIT ahora.

---

# Paso 3 · El despliegue

## 3.1 Fusionar y publicar

En su máquina:

```bash
git checkout master
git merge fases-6-10-numeracion-fiscal-dian
git push origin master
```

## 3.2 Copia de seguridad, y comprobarla

En el servidor:

```bash
mysqldump -u USUARIO -p --single-transaction --routines BASE | gzip > ~/respaldo-antes-dian-$(date +%F-%H%M).sql.gz
ls -lh ~/respaldo-antes-dian-*.sql.gz
```

**Compruebe que se puede restaurar.** Un respaldo que no se ha probado
no es un respaldo:

```bash
gunzip -t ~/respaldo-antes-dian-*.sql.gz && echo "El archivo está íntegro"
```

Copie además los certificados y los archivos subidos:

```bash
tar czf ~/respaldo-storage-$(date +%F).tar.gz storage/app
```

## 3.3 Parar el tráfico

```bash
cd /var/www/gestisp
php artisan down --render="errors::503" --retry=60
```

Pare también el worker de la cola, si lo tiene corriendo:

```bash
sudo supervisorctl stop gestisp-worker
```

## 3.4 Traer el código

```bash
git fetch origin
git checkout master
git pull origin master
composer install --no-dev --optimize-autoloader
```

## 3.5 Variables nuevas

Las tres son opcionales: sin ellas el sistema funciona con sus valores
por defecto, que son los correctos para producción.

```bash
cat >> .env <<'EOF'

# ---- Facturación electrónica DIAN ----
DIAN_ENDPOINT=
DIAN_TIMEOUT=60
DIAN_TRANSPORT=auto
EOF
```

Compruebe también que la cola no esté en `sync`:

```bash
grep QUEUE_CONNECTION .env
```

Debe decir `database`.

## 3.6 Migrar

**El paso sin retorno.** A partir de aquí, si algo falla, se vuelve
restaurando el respaldo.

```bash
php artisan migrate --force
```

Si algo falla a mitad, **no lo reintente a ciegas**: lea el error, vaya
al paso "Si algo falla" del final.

## 3.7 Permisos y catálogos

```bash
php artisan permissions:sync
php artisan dian:catalogos
```

`permissions:sync` es **obligatorio**: sin él los permisos nuevos
(`dian.index`, `dian.manage`, `retentions.*`, y los demás de estas
fases) no existen en la base y sus opciones no aparecen en el menú.

## 3.8 Migrar los contadores de numeración

Primero mirando:

```bash
php artisan numeracion:migrar --dry-run
```

Y si el informe cuadra:

```bash
php artisan numeracion:migrar
```

## 3.9 Revisar el enlace empresa-sucursal

```bash
php artisan gestisp:empresas-migrar
```

Sin `--aplicar` solo informa. Léalo: le dirá si alguna sucursal quedó
mal enlazada. Si hay algo que reparar:

```bash
php artisan gestisp:empresas-migrar --aplicar
```

## 3.10 Cachés

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

## 3.11 Levantar

```bash
php artisan up
sudo supervisorctl start gestisp-worker
```

---

# Paso 4 · Verificar

No dé el despliegue por bueno hasta que estos cinco pasen.

## 4.1 Que la aplicación responde

Entre con un usuario real. Compruebe que puede elegir contexto, que ve
sus sucursales y que el menú aparece completo.

## 4.2 Que las empresas se crearon bien

```sql
SELECT c.id, c.legal_name, c.document_number, COUNT(b.id) AS sucursales
FROM companies c LEFT JOIN branches b ON b.company_id = c.id
GROUP BY c.id ORDER BY c.id;
```

Busque dos cosas: ninguna empresa con `SIN-NIT-`, y ninguna sucursal
suelta. Este debe dar **cero**:

```sql
SELECT COUNT(*) FROM branches WHERE company_id IS NULL;
```

Corrija las razones sociales desde **Empresas**: la migración pone el
nombre de la primera sucursal como punto de partida, que no es una razón
social.

## 4.3 Que nada perdió su empresa

```sql
SELECT 'contracts' t, COUNT(*) n FROM contracts WHERE company_id IS NULL AND branch_id IS NOT NULL
UNION ALL SELECT 'invoices', COUNT(*) FROM invoices WHERE company_id IS NULL AND branch_id IS NOT NULL
UNION ALL SELECT 'clients',  COUNT(*) FROM clients  WHERE company_id IS NULL AND branch_id IS NOT NULL
UNION ALL SELECT 'payments_lote', COUNT(*) FROM payment_batches WHERE company_id IS NULL AND branch_id IS NOT NULL;
```

Todo debe dar **cero**. Si no, ejecute `gestisp:empresas-migrar --aplicar`.

## 4.4 La clasificación fiscal de los servicios

**Esto hay que revisarlo a mano.** La migración clasificó adivinando por
la tarifa: todo lo que estaba al 0% quedó como **excluido**.

```sql
SELECT id, name, tax_percentage, tax_classification
FROM services ORDER BY tax_classification, name;
```

La regla real:

- Internet residencial de estratos 1, 2 y 3 → **excluido**. Correcto.
- Televisión, arriendos de equipo, instalaciones → normalmente
  **gravado**. Si quedaron en excluido, están mal.

Corríjalo desde **Servicios**, no por SQL: la pantalla valida la
combinación de clasificación y tarifa.

## 4.5 Que la facturación sigue funcionando

Lo más importante y lo que más se olvida. Genere **una** factura de
prueba de un contrato real y compruebe:

- Que toma número de la serie interna (no de un rango DIAN: todavía no
  hay ninguno).
- Que el PDF sale bien y **sin** bloque DIAN.
- Que el correo al cliente sale.

```bash
php artisan dian:diagnostico
```

Debe decir que falta el certificado y la resolución. Eso es lo correcto:
significa que el módulo está instalado y sabe que aún no puede emitir.

---

# Paso 5 · Encender lo que hace falta

## 5.1 El cron

Una sola línea, si no la tenía:

```
* * * * * cd /var/www/gestisp && php artisan schedule:run >> /dev/null 2>&1
```

De ahí salen los sondeos de red, los avisos de factura, `dian:alertas` y
`dian:transmitir`.

## 5.2 El worker de la cola

Con `QUEUE_CONNECTION=database` hace falta un proceso permanente:

```bash
php artisan queue:work --tries=3 --timeout=120
```

En producción, bajo supervisor. **Sin worker, los documentos
electrónicos se firman pero no se transmiten** hasta que
`dian:transmitir` los recoja a la hora siguiente.

## 5.3 Retención de trazabilidad

Para que `audits` no vuelva a crecer sin control, añada al planificador
o al cron:

```
0 3 * * 0 cd /var/www/gestisp && php artisan audits:prune --days=365
```

---

# Si algo falla

## Durante la migración

**No reintente `migrate` a ciegas.** Laravel marca cada migración como
aplicada al terminarla; si una falló a mitad, la tabla puede haber
quedado con la columna pero sin el relleno.

1. Lea el error completo y anote **qué migración** falló.
2. Mire si esa migración quedó registrada:
   ```sql
   SELECT * FROM migrations ORDER BY id DESC LIMIT 5;
   ```
3. Si el error es de datos (una sucursal sin NIT, un duplicado),
   corrija el dato y vuelva a `migrate --force`.
4. Si no lo tiene claro, **restaure el respaldo**. Es lo que está ahí
   para eso.

## Volver atrás

```bash
php artisan down
mysql -u USUARIO -p BASE < ~/respaldo-antes-dian-FECHA.sql
git checkout 2a91e9d
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache
php artisan up
```

> **`migrate:rollback` no sirve aquí.** El `down()` de
> `link_branches_to_companies` **borra la tabla `companies` entera**, y
> con ella cualquier razón social que ya se hubiera corregido a mano.
> La vuelta atrás es restaurar el respaldo, no deshacer migraciones.

## Errores conocidos

| Síntoma | Causa | Solución |
|---|---|---|
| `Column 'company_id' cannot be null` en branches | Una sucursal sin NIT no recibió empresa | Paso 2.2, y volver a migrar |
| `Duplicate entry ... for key 'branches_company_name_unique'` | Dos sucursales con el mismo nombre y el mismo NIT | Renombre una |
| No aparece el menú DIAN | Faltó `permissions:sync` | Paso 3.7 |
| `Class "Milon\Barcode\Facades\DNS2DFacade" not found` | Faltó `composer install` | Paso 3.4 |
| Los documentos se quedan en `signed` | No hay worker de cola | Paso 5.2 |
| El QR no sale en el PDF | Caché de vistas vieja | `php artisan view:clear` |

---

# Resumen: la secuencia mínima

Para tenerlo todo junto. **No la ejecute sin haber leído lo de arriba.**

```bash
# --- En su máquina ---
git checkout master
git merge fases-6-10-numeracion-fiscal-dian
git push origin master

# --- En el servidor ---
cd /var/www/gestisp
mysqldump -u USUARIO -p --single-transaction BASE | gzip > ~/respaldo-antes-dian-$(date +%F-%H%M).sql.gz
php artisan audits:prune --days=365          # antes, con el sistema arriba
php artisan down --retry=60
sudo supervisorctl stop gestisp-worker
git pull origin master
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan permissions:sync
php artisan dian:catalogos
php artisan numeracion:migrar
php artisan gestisp:empresas-migrar
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan up
sudo supervisorctl start gestisp-worker
```
