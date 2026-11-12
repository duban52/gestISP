/**
 * Errores de las peticiones AJAX, dichos de forma que se entiendan.
 *
 * QUÉ OFRECE
 * ----------
 *   GestispErrores.mensaje(estado, cuerpo)
 *       Traduce una respuesta fallida a una frase para el usuario:
 *       por el código HTTP y, si el servidor mandó JSON, con sus
 *       errores de validación o con el código del incidente
 *       (ERR-XXXXXXXX) para poder buscarlo en el log.
 *
 *   GestispErrores.avisar(texto)
 *       Lo enseña como aviso en la esquina de la pantalla.
 *
 * QUÉ HACE SOLO, Y POR QUÉ SOLO ESO
 * ---------------------------------
 * Avisa UNA vez cuando una petición vuelve con 401 o 419: la sesión
 * caducó. Es el único caso que se trata de forma global porque es el
 * único que es igual en todas las pantallas y que hoy falla en
 * silencio en casi todas — el botón «no hace nada» y la causa es que
 * hay que volver a entrar.
 *
 * El resto de errores NO se anuncian desde aquí a propósito: muchas
 * pantallas ya pintan el suyo junto al botón que falló, y un aviso
 * global encima diría lo mismo dos veces. Para esas está `mensaje()`,
 * que pueden ir adoptando sin que cambie nada de golpe.
 */
(function () {
    'use strict';

    var POR_ESTADO = {
        0: 'No fue posible completar la operación por un problema de conexión. Inténtelo nuevamente.',
        401: 'La sesión caducó. Recargue la página e inicie sesión de nuevo.',
        403: 'No tiene permiso para realizar esta acción.',
        404: 'El registro que buscaba ya no existe o fue eliminado.',
        419: 'La sesión caducó. Recargue la página y vuelva a intentarlo.',
        422: 'Hay datos que corregir antes de continuar.',
        429: 'Demasiadas solicitudes seguidas. Espere un momento e inténtelo de nuevo.',
        500: 'Se presentó un inconveniente al procesar la solicitud.',
        503: 'El sistema está en mantenimiento. Inténtelo de nuevo en unos minutos.'
    };

    /**
     * @param {number} estado  Código HTTP (0 si no hubo respuesta)
     * @param {object=} cuerpo La respuesta JSON del servidor, si la hubo
     */
    function mensaje(estado, cuerpo) {
        cuerpo = cuerpo || {};

        // Validación: lo útil es QUÉ campo y por qué, no «hay errores».
        if (estado === 422 && cuerpo.errors) {
            var detalles = [];

            Object.keys(cuerpo.errors).forEach(function (campo) {
                detalles = detalles.concat(cuerpo.errors[campo]);
            });

            if (detalles.length) {
                return detalles.join(' ');
            }
        }

        // Un fallo inesperado: el servidor ya redactó la frase con el
        // código del incidente. Si no vino, se arma aquí con él.
        if (estado >= 500) {
            if (cuerpo.incidente) {
                return POR_ESTADO[500] + ' El incidente quedó registrado con el código '
                    + cuerpo.incidente + '. Si el problema persiste, comuníquelo a soporte técnico.';
            }

            return POR_ESTADO[estado] || POR_ESTADO[500];
        }

        // Rechazos con explicación propia (403 con motivo, por ejemplo).
        if (cuerpo.message && estado !== 419 && estado !== 401) {
            return cuerpo.message;
        }

        return POR_ESTADO[estado] || 'No se pudo completar la operación (error ' + estado + ').';
    }

    function avisar(texto, titulo) {
        var $ = window.jQuery;

        // AdminLTE trae los avisos tipo «toast». Si por lo que sea no
        // están, un alert sigue siendo mejor que el silencio.
        if ($ && $.fn && $.fn.Toasts) {
            $(document).Toasts('create', {
                class: 'bg-danger',
                title: titulo || 'No se pudo completar la operación',
                body: $('<div>').text(texto).html(),
                autohide: true,
                delay: 10000
            });

            return;
        }

        window.alert(texto);
    }

    window.GestispErrores = { mensaje: mensaje, avisar: avisar };

    /* ---------- Sesión caducada: el único aviso global ---------- */
    var yaAvisado = false;

    if (window.jQuery) {
        window.jQuery(document).ajaxError(function (evento, xhr) {
            if (yaAvisado || (xhr.status !== 401 && xhr.status !== 419)) {
                return;
            }

            // Una sola vez por página: una pantalla que sondea cada
            // pocos segundos llenaría la esquina de avisos idénticos.
            yaAvisado = true;
            avisar(mensaje(xhr.status), 'Sesión caducada');
        });
    }
})();
