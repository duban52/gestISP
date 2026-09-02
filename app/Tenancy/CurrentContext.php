<?php

namespace App\Tenancy;

use App\Models\Branch;
use RuntimeException;

/**
 * El contexto de empresa con el que trabaja la peticion en curso.
 *
 * QUE PROBLEMA RESUELVE
 * ---------------------
 * Hasta ahora el aislamiento entre sucursales dependia de que quien
 * escribiera la consulta se acordara de poner
 * `where('branch_id', session('branch_id'))`. Hay 113 de esos en 31
 * controladores. Con una sola empresa, olvidarse uno es un fallo
 * menor; con dos empresas es una fuga de datos entre contribuyentes.
 *
 * Esta clase es de donde el global scope saca "quien esta mirando".
 *
 * CUANDO ESTA ACTIVO Y CUANDO NO
 * ------------------------------
 * Se activa en las peticiones web, desde el middleware, con lo que
 * haya en la sesion. NO se activa solo en consola ni en los trabajos
 * en cola, y eso es deliberado: un poller de ONT o una corrida de
 * facturacion tienen que poder recorrer varias empresas, y si el
 * scope se aplicara con un contexto vacio dejarian de ver nada.
 *
 * La contrapartida es que el codigo que corre sin contexto tiene que
 * acotar por su cuenta. Por eso los trabajos reciben la empresa de
 * forma explicita y nunca la leen de la sesion.
 *
 * SE GUARDA COMO SINGLETON
 * ------------------------
 * Una instancia por peticion. Se registra en el contenedor para poder
 * sustituirla en las pruebas sin tocar la sesion.
 */
class CurrentContext
{
    private ?int $companyId = null;

    /** @var array<int, int> */
    private array $branchIds = [];

    private ?int $branchId = null;

    private bool $activo = false;

    /**
     * Fija el contexto.
     *
     * @param  array<int, int>  $branchIds  Sucursales que el usuario ve aqui
     * @param  int|null  $branchId  La sucursal activa; null en panel consolidado
     */
    public function establecer(int $companyId, array $branchIds, ?int $branchId = null): void
    {
        $this->companyId = $companyId;
        $this->branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        $this->branchId = $branchId;
        $this->activo = true;
    }

    /**
     * Deduce el contexto a partir de una sucursal.
     *
     * Es el camino que se usa hoy: la sesion guarda una sucursal y de
     * ahi sale la empresa. Cuando exista el selector de contexto, la
     * empresa vendra elegida y esto quedara para el modo de una sola
     * sucursal.
     */
    public function establecerDesdeSucursal(int $branchId): void
    {
        $sucursal = Branch::find($branchId);

        if (!$sucursal) {
            return;
        }

        $this->establecer((int) $sucursal->company_id, [$branchId], $branchId);
    }

    /**
     * Deja la peticion sin contexto: el scope no se aplica.
     *
     * Solo para procesos que abarcan varias empresas a proposito.
     */
    public function limpiar(): void
    {
        $this->companyId = null;
        $this->branchIds = [];
        $this->branchId = null;
        $this->activo = false;
    }

    /**
     * Ejecuta algo SIN contexto y lo restaura despues.
     *
     * Para lo que legitimamente recorre varias empresas: comandos de
     * mantenimiento, informes de plataforma. Restaura siempre, aunque
     * lo de dentro lance una excepcion.
     */
    public function sinContexto(callable $callback): mixed
    {
        $estado = [$this->companyId, $this->branchIds, $this->branchId, $this->activo];

        $this->limpiar();

        try {
            return $callback();
        } finally {
            [$this->companyId, $this->branchIds, $this->branchId, $this->activo] = $estado;
        }
    }

    public function activo(): bool
    {
        return $this->activo;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    /** La sucursal activa, o null si se trabaja consolidado. */
    public function branchId(): ?int
    {
        return $this->branchId;
    }

    /** @return array<int, int> */
    public function branchIds(): array
    {
        return $this->branchIds;
    }

    /** ¿Se estan viendo varias sucursales a la vez? */
    public function esConsolidado(): bool
    {
        return $this->activo && $this->branchId === null;
    }

    /**
     * La sucursal con la que hay que GUARDAR este registro.
     *
     * POR QUE HACE FALTA UN METODO Y NO session('branch_id')
     * ------------------------------------------------------
     * Hasta ahora, crear cualquier cosa era
     * `'branch_id' => session('branch_id')`. Eso funciona mientras
     * SIEMPRE haya una sucursal activa, pero en panel consolidado no la
     * hay: el usuario trabaja varias a la vez y la sucursal la elige
     * al crear.
     *
     * Con la sesion a null, esas 13 escrituras guardarian branch_id
     * nulo. En 24 tablas eso revienta con un error de la base —feo
     * pero seguro—; en `invoices` y `cash_registers`, que lo admiten
     * nulo, crearia una factura o una caja SIN SUCURSAL, invisible en
     * todos los listados. Ese es el fallo que este metodo evita.
     *
     * COMO SE COMPORTA
     * ----------------
     *   Modo independiente → manda la sucursal activa. Lo que llegue
     *                        del formulario se ignora: el usuario ya
     *                        eligio al entrar y no puede escribir en
     *                        otra sede desde aqui.
     *
     *   Panel consolidado  → hay que decir cual, y tiene que estar
     *                        entre las suyas. Sin eso, falla.
     *
     * @throws RuntimeException     si no hay contexto (fallo del programa)
     * @throws SucursalNoIndicada   si falta la sucursal o no es suya
     */
    public function branchParaEscritura(int|string|null $solicitada = null): int
    {
        if (!$this->activo) {
            throw new RuntimeException(
                'No hay contexto de trabajo: no se puede determinar la sucursal.'
            );
        }

        if ($this->branchId !== null) {
            return $this->branchId;
        }

        // Si el usuario solo alcanza UNA sucursal, no hay nada que
        // elegir: se asume. Pasa con una empresa de una sola sede y
        // tambien con quien, en panel consolidado, solo tiene acceso a
        // una. Preguntar cuando no hay alternativa es un campo de mas
        // en cada formulario.
        if (count($this->branchIds) === 1) {
            return $this->branchIds[0];
        }

        if (!$this->permiteSucursal($solicitada)) {
            // SucursalNoIndicada, y no RuntimeException a secas: esto
            // es un campo sin rellenar, no un fallo del programa. La
            // excepcion sabe pintarse sola como error de formulario
            // (o como 422 si la peticion es JSON) en vez de dar un 500.
            // Hereda de RuntimeException, asi que nada de lo que ya la
            // capturaba deja de hacerlo.
            throw new SucursalNoIndicada();
        }

        return (int) $solicitada;
    }

    /**
     * ¿Hay que preguntar en que sucursal se registra?
     *
     * Solo cuando de verdad hay alternativa. Con una sola sucursal
     * alcanzable —empresa de una sede, o usuario con acceso a una en
     * panel consolidado— se asume y el formulario no muestra el campo.
     *
     * Es lo que decide si el selector aparece; el servidor no depende
     * de esto: branchParaEscritura() vuelve a resolverlo por su cuenta.
     */
    public function hayQueElegirSucursal(): bool
    {
        return $this->activo && $this->branchId === null && count($this->branchIds) > 1;
    }

    /**
     * Las sucursales entre las que se puede elegir, para pintarlas.
     *
     * @return \Illuminate\Support\Collection<int, Branch>
     */
    public function sucursalesElegibles()
    {
        return Branch::withoutGlobalScope('empresa')
            ->whereIn('id', $this->branchIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * ¿Hay que mostrar la sucursal en los listados?
     *
     * Solo cuando de verdad aporta: en panel consolidado y con más de
     * una sucursal alcanzable. Trabajando en una sola sede, una
     * columna que repite el mismo valor en todas las filas es ruido —
     * y en móvil, ruido que empuja las columnas útiles fuera de la
     * pantalla.
     *
     * Es el mismo criterio que hayQueElegirSucursal(), pero se
     * pregunta aparte porque son dos decisiones distintas: una es «hay
     * que preguntar dónde se guarda», la otra «hay que decir de dónde
     * es cada fila». Hoy coinciden; si mañana una cambia, la otra no
     * tiene por qué seguirla.
     */
    public function mostrarSucursal(): bool
    {
        return $this->activo && $this->branchId === null && count($this->branchIds) > 1;
    }

    /**
     * Acota una consulta a las sucursales del contexto.
     *
     * POR QUE NO SE ESCRIBE EL whereIn A MANO
     * ---------------------------------------
     * Porque `whereIn('branch_id', [])` NO significa «no filtres»:
     * significa «escóndelo todo». Y branchIds() viene vacío justo
     * cuando no hay contexto — una tarea en cola, un comando de
     * consola, el sondeo de los routers.
     *
     * Escrito a mano, eso convierte una corrida de facturación en una
     * que ve CERO contratos y no da ningún error: produce un resultado
     * vacío en silencio, que es la peor forma de fallar.
     *
     * La regla ya estaba decidida para el global scope de empresa —
     * sin contexto no se filtra, y es deliberado, para que los
     * sondeos y las colas puedan atravesar empresas. Esto la aplica
     * igual en el resto. La barrera de seguridad es el middleware,
     * que garantiza contexto en TODA petición de un usuario.
     *
     * @template TQuery
     * @param  TQuery  $query
     * @return TQuery
     */
    public function limitarSucursales($query, string $columna = 'branch_id')
    {
        $ids = $this->branchIds();

        return $ids === [] ? $query : $query->whereIn($columna, $ids);
    }

    /**
     * ¿El usuario puede operar sobre esta sucursal?
     *
     * Es la comprobacion que hay que hacer con TODO branch_id que
     * llegue de un formulario: en panel consolidado el usuario elige
     * sucursal al crear un documento, y esa eleccion no puede salirse
     * de lo que tiene concedido.
     */
    public function permiteSucursal(int|string|null $branchId): bool
    {
        if ($branchId === null || $branchId === '') {
            return false;
        }

        return in_array((int) $branchId, $this->branchIds, true);
    }
}
