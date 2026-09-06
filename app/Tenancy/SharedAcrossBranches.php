<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Catálogo que puede ser de la empresa o de una sede concreta.
 *
 * LA REGLA, EN UN SOLO SITIO
 * --------------------------
 * `branch_id = NULL` significa «de la EMPRESA»: disponible en todas sus
 * sucursales. Con un id, es exclusivo de esa sede.
 *
 * Es la misma semántica que ya tenía `numbering_ranges`, así que no hay
 * que aprenderse nada nuevo: un rango sin sucursal vale para toda la
 * empresa.
 *
 * POR QUÉ UN TRAIT Y NO NUEVE CONDICIONES
 * ---------------------------------------
 * Antes había nueve consultas repartidas —dos controladores, el
 * formulario de contrato, el importador— cada una escribiendo
 * `whereIn('branch_id', branchIds())` a mano. Añadir el caso del nulo
 * en nueve sitios es exactamente cómo uno de ellos se queda sin
 * actualizar, y entonces el catálogo compartido aparece en unas
 * pantallas y no en otras, sin ningún error que lo delate.
 *
 * Aquí la regla se escribe una vez. Si mañana cambia, cambia una vez.
 *
 * OJO: ESTO NO SUSTITUYE AL ALCANCE DE EMPRESA
 * --------------------------------------------
 * `BelongsToCompany` sigue filtrando por `company_id`, y es lo que
 * impide ver el catálogo de OTRA empresa. Esto solo decide, dentro de
 * la empresa, qué sedes alcanzan a cada fila.
 */
trait SharedAcrossBranches
{
    /**
     * Lo que está disponible en estas sucursales.
     *
     * Incluye lo de la empresa (sin sucursal) y lo propio de cada una
     * de las indicadas.
     *
     * El `where` envolvente NO sobra: sin él, el `orWhere` se saldría
     * del paréntesis y anularía cualquier condición anterior de la
     * consulta —incluido el filtro de empresa—. Es el fallo clásico de
     * mezclar `or` con otros filtros, y aquí significaría enseñar el
     * catálogo de otro contribuyente.
     *
     * @param  array<int, int|string>  $branchIds
     */
    public function scopeDisponiblesEn(Builder $query, array $branchIds): Builder
    {
        return $query->where(function (Builder $q) use ($branchIds) {
            $q->whereNull($q->getModel()->qualifyColumn('branch_id'));

            if ($branchIds !== []) {
                $q->orWhereIn($q->getModel()->qualifyColumn('branch_id'), $branchIds);
            }
        });
    }

    /**
     * Lo disponible en la sucursal de trabajo actual.
     *
     * Atajo para el caso normal. En consolidado son varias, y entonces
     * alcanza todas las del contexto.
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->disponiblesEn(app(CurrentContext::class)->branchIds());
    }

    /** ¿Es del catálogo de la empresa, y no de una sede? */
    public function esDeLaEmpresa(): bool
    {
        return $this->branch_id === null;
    }

    /**
     * ¿Se puede usar desde esta sucursal?
     *
     * Lo usa la validación al guardar un contrato: pedir por POST un
     * plan de otra sede no puede valer solo porque exista.
     */
    public function disponibleEn(int|string|null $branchId): bool
    {
        return $this->branch_id === null
            || (int) $this->branch_id === (int) $branchId;
    }
}
