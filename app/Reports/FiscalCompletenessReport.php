<?php

namespace App\Reports;

use App\Models\Client;
use App\Models\Company;
use App\Models\FiscalCatalog;
use App\Models\Service;
use App\Tenancy\CurrentContext;
use Illuminate\Support\Collection;

/**
 * Qué falta para poder emitir factura electrónica.
 *
 * PARA QUÉ SIRVE
 * --------------
 * Los datos fiscales de la fase 8 nacen todos vacíos, y con razón:
 * exigirlos al dar de alta un cliente dejaría sin poder trabajar a
 * quien solo quiere instalarle internet. Pero eso deja un problema —
 * nadie sabe cuánto falta hasta el día que se intenta emitir, y ese
 * día es tarde.
 *
 * Este informe contesta esa pregunta antes: cuántos clientes,
 * servicios y empresas están incompletos, qué les falta exactamente, y
 * cuáles son los primeros que hay que arreglar.
 *
 * QUÉ CUENTA COMO «INCOMPLETO»
 * ----------------------------
 * Solo lo que el XML exige de verdad. No se inventan requisitos: cada
 * campo de esta lista sale del anexo técnico, y se comprueba contra el
 * catálogo cuando el campo es un código — porque un código que ya no
 * está vigente es tan inservible como uno vacío, y esa diferencia no
 * se ve mirando si la columna está llena.
 *
 * SOLO MIRA LO QUE VA A FACTURAR ELECTRÓNICAMENTE
 * -----------------------------------------------
 * Un cliente cuyos contratos son todos de grupos internos no necesita
 * datos fiscales completos, y contarlo como incompleto sería ruido que
 * esconde los que sí importan. Se puede pedir el informe completo, pero
 * lo que se muestra de entrada son los que van a hacer falta.
 */
class FiscalCompletenessReport
{
    /**
     * Campos del cliente que exige el XML, con su nombre legible y el
     * catálogo contra el que se valida si es un código.
     */
    private const CAMPOS_CLIENTE = [
        'document_type_code' => ['Tipo de documento', FiscalCatalog::TIPO_DOCUMENTO],
        'identity_number' => ['Número de documento', null],
        'organization_type_code' => ['Tipo de organización', FiscalCatalog::TIPO_ORGANIZACION],
        'fiscal_address' => ['Dirección fiscal', null],
        'department_dane_code' => ['Departamento', FiscalCatalog::DEPARTAMENTO],
        'municipality_dane_code' => ['Municipio', FiscalCatalog::MUNICIPIO],
        'email' => ['Correo electrónico', null],
    ];

    /** Campos del servicio. */
    private const CAMPOS_SERVICIO = [
        'product_code' => ['Código de producto', null],
        'product_code_type' => ['Tipo de código de producto', FiscalCatalog::TIPO_CODIGO_PRODUCTO],
        'unit_measure_code' => ['Unidad de medida', FiscalCatalog::UNIDAD_MEDIDA],
    ];

    /** Campos de la empresa, que es quien emite. */
    private const CAMPOS_EMPRESA = [
        'legal_name' => ['Razón social', null],
        'document_type_code' => ['Tipo de documento', FiscalCatalog::TIPO_DOCUMENTO],
        'document_number' => ['NIT', null],
        'verification_digit' => ['Dígito de verificación', null],
        'organization_type_code' => ['Tipo de organización', FiscalCatalog::TIPO_ORGANIZACION],
        'address' => ['Dirección', null],
        'department_dane_code' => ['Departamento', FiscalCatalog::DEPARTAMENTO],
        'municipality_dane_code' => ['Municipio', FiscalCatalog::MUNICIPIO],
        'email' => ['Correo electrónico', null],
    ];

    public function __construct(
        /** Si true, mira TODOS; si false, solo los que van a facturar electrónicamente. */
        private readonly bool $incluirTodos = false,
    ) {
    }

    /**
     * El resumen: cuántos van y cuántos faltan de cada cosa.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $clientes = $this->clientes();
        $servicios = $this->servicios();
        $empresa = $this->empresa();

        return [
            'clientes' => [
                'total' => $clientes->count(),
                'incompletos' => $clientes->where('faltan', '!=', [])->count(),
            ],
            'servicios' => [
                'total' => $servicios->count(),
                'incompletos' => $servicios->where('faltan', '!=', [])->count(),
            ],
            'empresa' => [
                'nombre' => $empresa['nombre'],
                'faltan' => $empresa['faltan'],
            ],
            // La empresa es lo primero: sin sus datos no se emite NADA,
            // por muchos clientes completos que haya.
            'bloqueante' => $empresa['faltan'] !== [],
        ];
    }

    /**
     * Clientes con lo que le falta a cada uno.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function clientes(): Collection
    {
        $consulta = Client::query()->with('contracts.affinityGroup');

        return $consulta->get()
            ->when(
                !$this->incluirTodos,
                fn (Collection $c) => $c->filter(fn (Client $cliente) => $this->facturaElectronicamente($cliente)),
            )
            ->map(fn (Client $cliente) => [
                'id' => $cliente->id,
                'nombre' => trim($cliente->name . ' ' . $cliente->last_name),
                'documento' => $cliente->identity_number,
                'faltan' => $this->faltantes($cliente, self::CAMPOS_CLIENTE),
            ])
            ->sortByDesc(fn (array $fila) => count($fila['faltan']))
            ->values();
    }

    /**
     * Servicios con lo que le falta a cada uno.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function servicios(): Collection
    {
        return Service::query()
            ->whereIn('branch_id', app(CurrentContext::class)->branchIds())
            ->get()
            ->map(fn (Service $servicio) => [
                'id' => $servicio->id,
                'nombre' => $servicio->name,
                'faltan' => $this->faltantes($servicio, self::CAMPOS_SERVICIO),
            ])
            ->sortByDesc(fn (array $fila) => count($fila['faltan']))
            ->values();
    }

    /**
     * Lo que le falta a la empresa del contexto.
     *
     * @return array<string, mixed>
     */
    public function empresa(): array
    {
        $empresa = Company::find(app(CurrentContext::class)->companyId());

        if (!$empresa) {
            return ['nombre' => null, 'faltan' => []];
        }

        $faltan = $this->faltantes($empresa, self::CAMPOS_EMPRESA);

        // Las responsabilidades fiscales van aparte: no son una columna
        // sino una tabla, y no tenerlas es tan bloqueante como no tener
        // el NIT.
        if ($empresa->taxResponsibilities()->count() === 0) {
            $faltan[] = 'Responsabilidades fiscales';
        }

        return ['nombre' => $empresa->nombreVisible(), 'faltan' => $faltan];
    }

    // ==================== Apoyo ====================

    /**
     * Qué campos le faltan a un registro.
     *
     * Un código que existe pero ya NO está vigente cuenta como
     * faltante: es tan inservible como uno vacío, y esa diferencia no
     * se ve mirando si la columna está llena.
     *
     * @param  array<string, array{0: string, 1: string|null}>  $campos
     * @return array<int, string>
     */
    private function faltantes(object $registro, array $campos): array
    {
        $faltan = [];

        foreach ($campos as $columna => [$etiqueta, $catalogo]) {
            $valor = $registro->{$columna} ?? null;

            if ($valor === null || $valor === '') {
                $faltan[] = $etiqueta;

                continue;
            }

            if ($catalogo !== null && !FiscalCatalog::vigente($catalogo, (string) $valor)) {
                $faltan[] = $etiqueta . ' (código no vigente)';
            }
        }

        return $faltan;
    }

    /**
     * ¿Este cliente va a recibir factura electrónica?
     *
     * Basta con que UNO de sus contratos esté en un grupo electrónico.
     * Un cliente cuyos contratos son todos internos no necesita datos
     * fiscales completos, y contarlo como incompleto sería ruido que
     * esconde los que sí importan.
     */
    private function facturaElectronicamente(Client $cliente): bool
    {
        return $cliente->contracts->contains(
            fn ($contrato) => $contrato->affinityGroup?->requires_electronic_invoicing === true,
        );
    }
}
