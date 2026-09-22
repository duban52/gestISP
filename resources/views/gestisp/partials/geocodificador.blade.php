{{--
    Buscador de direcciones de OpenStreetMap (Nominatim), en los dos
    sentidos: de dirección a punto y de punto a dirección.

    Lo incluyen el motor de los mapas y el parcial de dirección; @once
    hace que quede una sola copia aunque lo incluyan los dos. Solo se usa
    al pulsar un botón, así que da igual cuál de los dos lo cargue antes.

    LO QUE HAY QUE SABER DE NOMINATIM
    ---------------------------------
    - Es gratuito y pide no pasar de una consulta por segundo: pedir()
      las espacia.
    - No entiende la nomenclatura colombiana: «Calle 20 # 19-30» casi
      nunca da la placa exacta, a veces sí la calle, y siempre el
      municipio. Por eso buscar() va aflojando la consulta y dice hasta
      dónde llegó: el punto es una AYUDA para ubicar el mapa, no la
      ubicación definitiva.
    - Lo que se busca viaja a los servidores de OpenStreetMap.
--}}
@once
<script>
    window.GestispGeo = (function () {
        'use strict';

        const BASE = 'https://nominatim.openstreetmap.org/';
        let ultima = 0;

        function esperar(ms) {
            return new Promise(function (resolver) { setTimeout(resolver, ms); });
        }

        async function pedir(ruta) {
            const falta = 1100 - (Date.now() - ultima);

            if (falta > 0) {
                await esperar(falta);
            }

            ultima = Date.now();

            const respuesta = await fetch(BASE + ruta + '&format=jsonv2&accept-language=es');

            if (!respuesta.ok) {
                throw new Error('Nominatim respondió ' + respuesta.status);
            }

            return respuesta.json();
        }

        /**
         * De la consulta más precisa a la más floja:
         *   «Calle 20 19-30, Centro, Rionegro, Antioquia, Colombia»
         *   «Calle 20, Centro, Rionegro, Antioquia, Colombia»   (sin placa)
         *   «Centro, Rionegro, Antioquia, Colombia»
         *   «Rionegro, Antioquia, Colombia»
         */
        function candidatos(texto) {
            const limpio = texto
                .replace(/\s*(#|N[°º]\.?|No\.?(?=\s*\d))\s*/gi, ' ')
                .replace(/\s+/g, ' ')
                .trim();
            const lista = [{ q: limpio, nivel: null }];
            const sinPlaca = limpio.replace(/\s+\d{1,3}\s?[A-Z]?(\s?Bis)?(\s[A-Z])?\s*-\s*\d{1,3}[A-Z]?\b/i, '');

            if (sinPlaca !== limpio) {
                lista.push({ q: sinPlaca, nivel: 'la calle, sin la placa' });
            }

            let partes = limpio.split(',').map(function (p) { return p.trim(); }).filter(Boolean);

            while (partes.length > 3) {
                partes = partes.slice(1);
                lista.push({ q: partes.join(', '), nivel: '«' + partes[0] + '»' });
            }

            return lista;
        }

        /** @returns {Promise<{lat:number,lng:number,nivel:?string,aproximado:boolean}|null>} */
        async function buscar(texto) {
            const lista = candidatos(texto || '');

            for (let i = 0; i < lista.length; i++) {
                if (!lista[i].q) {
                    continue;
                }

                const resultados = await pedir('search?limit=1&countrycodes=co&q=' + encodeURIComponent(lista[i].q));

                if (resultados.length) {
                    return {
                        lat: parseFloat(resultados[0].lat),
                        lng: parseFloat(resultados[0].lon),
                        nivel: lista[i].nivel,
                        aproximado: i > 0,
                    };
                }
            }

            return null;
        }

        /** Lo que OpenStreetMap sabe de un punto, ya separado. */
        async function inverso(lat, lng) {
            const r = await pedir('reverse?addressdetails=1&zoom=18&lat=' + lat + '&lon=' + lng);
            const a = r.address || {};

            return {
                calle: a.road || '',
                placa: a.house_number || '',
                barrio: a.neighbourhood || a.suburb || a.quarter || a.hamlet || '',
                municipio: a.city || a.town || a.village || a.municipality || '',
                departamento: a.state || '',
                texto: r.display_name || '',
            };
        }

        return { buscar: buscar, inverso: inverso, candidatos: candidatos };
    })();
</script>
@endonce
