<?php

namespace App\Mail;

use App\Models\Branch;
use App\Models\MailSetting;
use Illuminate\Support\Facades\Config;

/**
 * El servidor de salida de una sucursal.
 *
 * A UN CLIENTE LE ESCRIBE SU OPERADOR
 * -----------------------------------
 * La factura, el aviso de vencimiento y la orden técnica salen de la
 * sede que lo atiende: con su dominio y con un buzón al que pueda
 * responder. Que una respuesta a la factura de San Andrés caiga en el
 * correo interno de GestISP no le sirve a nadie.
 *
 * SE RESUELVE AL ENVIAR, NO AL ENCOLAR
 * ------------------------------------
 * Las notificaciones van en cola: el correo se arma cuando el worker
 * lo procesa, no cuando se encola. Esto se llama desde
 * `ArmaCorreo::correo()`, que corre dentro del trabajo, así que cada
 * uno sale por su sucursal. Resolverlo al encolar habría hecho que
 * novecientas facturas salieran todas por la sucursal de la última
 * que se encoló — y eso no se nota hasta que un cliente contesta al
 * buzón equivocado.
 *
 * CADA SUCURSAL, SU MAILER
 * ------------------------
 * Laravel guarda los mailers ya construidos por nombre. Se registra
 * uno por sucursal («sucursal_7») la primera vez y se reutiliza: en
 * una corrida de mil facturas de cuatro sedes se abren cuatro
 * conexiones, no mil.
 *
 * PARA ESO ESTO ES UN SINGLETON (ver AppServiceProvider). Resuelto
 * con `app()` sin registrar, PHP devuelve una instancia nueva por
 * correo, `$registradas` nace vacía cada vez y se vuelve a registrar
 * el mailer — con lo que la conexión abierta se tira y se abre otra.
 * Mil facturas, mil conexiones. Se vio en una prueba: el mensaje que
 * una sucursal acababa de enviar desaparecía al registrar la
 * siguiente.
 */
class CorreoDeLaSucursal
{
    /** Sucursales ya registradas en esta ejecución. */
    private array $registradas = [];

    /**
     * El nombre del mailer por el que debe salir este correo.
     *
     * Devuelve `null` cuando la sucursal no tiene servidor propio: ahí
     * se usa el de siempre —el del sistema, o el `.env`—, que es lo
     * que hace que esto se pueda desplegar sin configurar nada y nada
     * cambie.
     *
     * EL INTERRUPTOR DE LA SEDE SE MIRA APARTE (`estaApagada`). Aquí
     * solo se resuelve POR DÓNDE saldría: si la sede está apagada el
     * correo no sale por ningún sitio, y eso lo cancela el oyente de
     * `MessageSending`, no esto.
     */
    public function mailerDe(?Branch $sucursal): ?string
    {
        if (!$sucursal) {
            return null;
        }

        $ajustes = MailSetting::deLaSucursal($sucursal->id);

        if (!$ajustes || !$ajustes->tieneServidorPropio()) {
            return null;
        }

        $nombre = 'sucursal_' . $sucursal->id;

        if (!isset($this->registradas[$nombre])) {
            $this->registrar($nombre, $ajustes);
            $this->registradas[$nombre] = true;
        }

        return $nombre;
    }

    /**
     * ¿Esta sucursal tiene el correo apagado?
     *
     * Apagado significa QUE NO SALE, no que salga por el servidor del
     * sistema. Es el mismo significado que el interruptor general,
     * acotado a una sede: sirve para cortar el correo de una sucursal
     * concreta —una migración de proveedor, un buzón que rebota todo—
     * sin dejar a las demás sin facturas.
     *
     * Solo cuenta si la sede tiene configuración propia: una sin
     * configurar no está apagada, está usando la del sistema.
     */
    public function estaApagada(?Branch $sucursal): bool
    {
        if (!$sucursal) {
            return false;
        }

        $ajustes = MailSetting::deLaSucursal($sucursal->id);

        return $ajustes !== null && !$ajustes->enabled;
    }

    /** Cabeceras internas: el oyente de MessageSending las lee y las quita. */
    public const CABECERA_SEDE = 'X-Gestisp-Sucursal';
    public const CABECERA_APAGADA = 'X-Gestisp-Sucursal-Apagada';

    /**
     * El remitente de una sucursal, si lo tiene.
     *
     * Va aparte del mailer porque Laravel resuelve el «from» del
     * mensaje, no del transporte: sin esto el correo saldría por el
     * servidor de la sede pero firmado por el del sistema.
     *
     * @return array{address: string, name: ?string}|null
     */
    public function remitenteDe(?Branch $sucursal): ?array
    {
        $ajustes = $sucursal ? MailSetting::deLaSucursal($sucursal->id) : null;

        if (!$ajustes || blank($ajustes->from_address)) {
            return null;
        }

        return [
            'address' => $ajustes->from_address,
            'name' => $ajustes->from_name ?: $sucursal->name,
        ];
    }

    private function registrar(string $nombre, MailSetting $ajustes): void
    {
        // EL TRANSPORTE SE HEREDA DEL MAILER POR DEFECTO, no se fija
        // en «smtp».
        //
        // Si alguien pone MAIL_MAILER=log para una ventana de
        // mantenimiento, o array en las pruebas, las sucursales tienen
        // que comportarse igual. Con «smtp» a fuego, el correo del
        // sistema se quedaba en un archivo y el de las sedes se iba de
        // verdad a los clientes — que es exactamente lo contrario de
        // lo que se pretendía al apagarlo.
        $pordefecto = config('mail.default', 'smtp');

        Config::set("mail.mailers.{$nombre}", array_merge(
            config("mail.mailers.{$pordefecto}", config('mail.mailers.smtp', [])),
            array_filter([
                'transport' => config("mail.mailers.{$pordefecto}.transport", 'smtp'),
                'host' => $ajustes->host,
                'port' => $ajustes->port,
                'username' => $ajustes->username,
                'password' => $ajustes->password,
                'encryption' => $ajustes->encryption,
            ], fn ($v) => $v !== null && $v !== ''),
        ));

        // El cifrado SÍ admite el vacío: «ninguno» es una opción y hay
        // servidores que la exigen. array_filter lo habría quitado.
        Config::set("mail.mailers.{$nombre}.encryption", $ajustes->encryption ?: null);

    }
}
