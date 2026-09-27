<?php

namespace App\Notifications\Concerns;

use App\Models\Branch;

/**
 * Los datos con los que la empresa se presenta al cliente.
 *
 * POR QUÉ ESTÁN AQUÍ Y NO EN CADA NOTIFICACIÓN
 * --------------------------------------------
 * Las plantillas de WhatsApp llevan el nombre de la empresa como
 * variable —«{{6}} le informa que…»—, así que siete notificaciones
 * distintas necesitan resolver exactamente lo mismo. Repetida siete
 * veces, la regla se desincroniza sola: bastó una para que el mensaje
 * saliera con el nombre de la SUCURSAL mientras los demás usaban el de
 * la empresa, y el cliente recibía dos remitentes distintos del mismo
 * negocio.
 *
 * QUÉ NOMBRE SE USA
 * -----------------
 * El comercial de la empresa (`trade_name`) antes que el legal, porque
 * es como el cliente conoce al operador. Si no hay empresa —datos
 * viejos, una sucursal suelta— se cae al nombre de la sucursal, y en
 * último extremo al del sistema. NUNCA vacío: Meta rechaza un
 * parámetro en blanco y el cliente se queda sin el aviso entero.
 */
trait HablaPorLaEmpresa
{
    protected function nombreDeLaEmpresa(?Branch $sucursal): string
    {
        return $sucursal?->company?->nombreVisible()
            ?: ($sucursal?->name ?: config('app.name'));
    }

    /**
     * El teléfono al que se le dice al cliente que escriba.
     *
     * El de la sucursal primero: es la que lo atiende. El de la empresa
     * como respaldo, que en una empresa de una sola sede es el mismo.
     */
    protected function telefonoDeLaEmpresa(?Branch $sucursal): string
    {
        return $sucursal?->number_phone
            ?: ($sucursal?->company?->phone ?: 'nuestros canales de atención');
    }

    /**
     * El hueco del enlace en la plantilla: LA URL PELADA.
     *
     * La plantilla ya dice «Descarguela en el siguiente enlace: {{5}}»,
     * así que el parámetro es solo el enlace; mandar la frase entera
     * haría que el cliente leyera la invitación dos veces.
     *
     * NUNCA VACÍO, por lo mismo de arriba: si el enlace no se pudo
     * armar —`APP_URL` mal puesta, por ejemplo— va la dirección del
     * sitio, que al menos lleva a alguna parte.
     */
    protected function enlaceParaLaPlantilla(?string $enlace): string
    {
        return $enlace ?: rtrim((string) config('app.url'), '/');
    }
}
