<?php

namespace App\Support;

/**
 * Importes escritos por una persona.
 *
 * En Colombia el precio se escribe `27.731,09`: el punto separa los
 * miles y la coma los decimales. Es lo que sale de la calculadora,
 * de Excel y de la factura del proveedor, y es lo que el usuario
 * teclea. PHP lee ese texto como `27` y Laravel lo rechaza con un
 * «debe ser un número» que no explica nada.
 *
 * Esto lo traduce, y aguanta también las otras formas en que llega
 * lo mismo: `27731.09`, `27,731.09`, `$ 27.731`, `27.731`.
 *
 * LA REGLA, CUANDO HAY DUDA
 * -------------------------
 * Con los dos separadores, manda el ÚLTIMO: es el decimal.
 * Con uno solo, decide cuántos dígitos quedan detrás —tres son
 * miles (`45.000`), uno o dos son decimales (`45,5`)—. No hay forma
 * de hacerlo mejor sin preguntarle al usuario: `1.500` es mil
 * quinientos para un colombiano y uno coma cinco para un inglés, y
 * aquí se factura en Colombia.
 *
 * Nació dentro del importador de clientes, donde llevaba meses
 * leyendo columnas de Excel de todas las formas. Salió de ahí el día
 * que el formulario de servicios rechazó un precio con decimales:
 * el mismo problema no puede tener dos respuestas distintas.
 */
class Dinero
{
    /**
     * Traduce un importe escrito a número. `null` si no hay nada.
     *
     * Un número de verdad (el que trae un Excel) pasa tal cual: no
     * hay separadores que interpretar.
     */
    public static function deTexto(mixed $valor): ?float
    {
        if ($valor === null || $valor === '' || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        if (is_int($valor) || is_float($valor)) {
            return round((float) $valor, 2);
        }

        $limpio = preg_replace('/[^\d,.\-]/', '', (string) $valor);

        if ($limpio === '' || $limpio === '-') {
            return null;
        }

        if (str_contains($limpio, ',') && str_contains($limpio, '.')) {
            // Con ambos, el último es el decimal
            $limpio = strrpos($limpio, ',') > strrpos($limpio, '.')
                ? str_replace(['.', ','], ['', '.'], $limpio)
                : str_replace(',', '', $limpio);
        } elseif (str_contains($limpio, ',')) {
            // Una sola coma: decimal si deja 1-2 dígitos detrás
            $decimales = strlen($limpio) - strrpos($limpio, ',') - 1;
            $limpio = $decimales <= 2 ? str_replace(',', '.', $limpio) : str_replace(',', '', $limpio);
        } elseif (substr_count($limpio, '.') > 1) {
            // 1.234.567 — varios puntos solo pueden ser miles
            $limpio = str_replace('.', '', $limpio);
        } elseif (str_contains($limpio, '.')) {
            $decimales = strlen($limpio) - strrpos($limpio, '.') - 1;

            if ($decimales === 3) {
                $limpio = str_replace('.', '', $limpio); // 45.000 = miles
            }
        }

        return is_numeric($limpio) ? round((float) $limpio, 2) : null;
    }

    /**
     * Lo mismo, pero nunca negativo ni nulo: para importes que por
     * definición no pueden bajar de cero (un precio, un abono).
     */
    public static function positivo(mixed $valor): float
    {
        return max(self::deTexto($valor) ?? 0.0, 0.0);
    }

    /**
     * Deja los campos de dinero de una petición en formato numérico,
     * ANTES de validarlos.
     *
     * Así `numeric` ve `27731.09` donde el usuario escribió
     * `27.731,09`, y el que escribió `27731.09` sigue pasando igual.
     * Un campo que no venga, o que venga vacío, se deja como está:
     * la validación es la que decide si era obligatorio.
     *
     * @param  array<int, string>  $campos
     */
    public static function normalizarEn(\Illuminate\Http\Request $peticion, array $campos): void
    {
        foreach ($campos as $campo) {
            $valor = $peticion->input($campo);

            if ($valor === null || $valor === '') {
                continue;
            }

            $numero = self::deTexto($valor);

            // Si no se pudo entender, se deja el texto original: que
            // el usuario vea su propio error, no un cero que nadie
            // escribió.
            if ($numero !== null) {
                $peticion->merge([$campo => $numero]);
            }
        }
    }
}
