<?php

namespace App\Tenancy;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Acota el modelo a la empresa del contexto y mantiene company_id al dia.
 *
 * HACE DOS COSAS
 * --------------
 * 1. LEER: anade un global scope que filtra por la empresa del
 *    contexto. Se aplica a TODA consulta del modelo, incluidas las que
 *    alguien escriba manana y se olvide de acotar. Esa es la gracia:
 *    la barrera no depende de que nadie se acuerde.
 *
 * 2. ESCRIBIR: al guardar, rellena company_id a partir de branch_id.
 *    Como company_id esta desnormalizado, podria divergir de la
 *    sucursal; derivarlo aqui hace que no haya ninguna via de
 *    escritura normal que los descuadre.
 *
 * NO SUSTITUYE AL FILTRO POR SUCURSAL
 * -----------------------------------
 * El filtro por sucursal sigue siendo el de siempre, escrito a mano en
 * cada consulta. Este trait anade la barrera que faltaba, la de
 * empresa, y no toca la otra. Son cosas distintas: dentro de una misma
 * empresa hay consultas que cruzan sucursales a proposito.
 *
 * COMO SALTARSELO CUANDO HAY QUE HACERLO
 * --------------------------------------
 *   Modelo::sinFiltroDeEmpresa()->...     una consulta concreta
 *   app(CurrentContext::class)->sinContexto(fn () => ...)   un bloque
 *
 * Las dos son explicitas y se ven al leer el codigo, que es la
 * condicion para que se puedan revisar.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('empresa', function (Builder $query) {
            $contexto = app(CurrentContext::class);

            // Sin contexto no se filtra: es lo que permite que los
            // pollers, la facturacion mensual y los comandos de
            // mantenimiento recorran varias empresas. El codigo que
            // corre asi acota por su cuenta.
            if (!$contexto->activo()) {
                return;
            }

            $query->where(
                $query->getModel()->qualifyColumn('company_id'),
                $contexto->companyId(),
            );
        });

        static::saving(function (Model $modelo) {
            $modelo->rellenarEmpresaDesdeSucursal();
        });
    }

    /**
     * Deriva company_id de la sucursal, o del contexto si no hay.
     */
    public function rellenarEmpresaDesdeSucursal(): void
    {
        if (!empty($this->company_id)) {
            return;
        }

        if (!empty($this->branch_id)) {
            $this->company_id = Branch::withoutGlobalScopes()
                ->whereKey($this->branch_id)
                ->value('company_id');

            return;
        }

        // Sin sucursal (por ejemplo, una accion de sistema en la
        // auditoria) se usa la empresa del contexto si la hay.
        $contexto = app(CurrentContext::class);

        if ($contexto->activo()) {
            $this->company_id = $contexto->companyId();
        }
    }

    /**
     * Consulta sin la barrera de empresa.
     *
     * Usarla es una decision consciente y hay que poder justificarla:
     * son consultas de plataforma, no de un usuario.
     */
    public function scopeSinFiltroDeEmpresa(Builder $query): Builder
    {
        return $query->withoutGlobalScope('empresa');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
