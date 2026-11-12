{{-- ============================================================
     Comportamiento del formulario de clientes (alta y edición)

     Parámetros
     ----------
     $formulario   id del <form>
     $boton        id del botón de guardar

     Tres cosas, y ninguna sustituye a la validación del servidor
     —que es la que manda—; solo evitan tropiezos:

       1. Que no se envíe dos veces.
       2. Que no se pierda lo escrito por pulsar «Cancelar» sin querer.
       3. Que no se pida la fecha de nacimiento a una empresa.
     ============================================================ --}}
<script>
    (function () {
        const formulario = document.getElementById(@json($formulario));
        const boton = document.getElementById(@json($boton));

        if (!formulario || !boton) {
            return;
        }

        /* ---------- 1. Doble envío ----------
           Un clic impaciente sobre «Guardar» mandaba el alta dos veces.
           La segunda la frena la regla de unicidad, pero el usuario
           veía «ya existe un cliente con ese documento» justo después
           de crearlo, y creía que había fallado.

           Se desactiva en el evento `submit`, que el navegador solo
           dispara cuando su propia validación ya pasó: si falta un
           campo obligatorio, el botón sigue vivo. */
        let enviando = false;

        formulario.addEventListener('submit', function (evento) {
            if (enviando) {
                evento.preventDefault();

                return;
            }

            enviando = true;
            boton.disabled = true;
            boton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Guardando…';
        });

        /* ---------- 2. Cancelar con datos escritos ----------
           Solo pregunta si de verdad se escribió algo: preguntar
           siempre enseña a contestar «sí» sin leer. */
        let conCambios = false;

        formulario.addEventListener('input', function () {
            conCambios = true;
        });

        document.querySelectorAll('[data-cancelar]').forEach(function (enlace) {
            enlace.addEventListener('click', function (evento) {
                if (conCambios && !enviando
                    && !confirm('Hay datos sin guardar. ¿Salir y descartarlos?')) {
                    evento.preventDefault();
                }
            });
        });

        /* ---------- 3. Persona jurídica ----------
           Una empresa no tiene fecha de nacimiento. El campo se
           esconde Y se vacía: escondido pero con valor, se enviaría
           una fecha que nadie ve. */
        const tipo = document.getElementById('type_client');
        const bloqueNacimiento = document.getElementById('bloqueNacimiento');
        const nacimiento = document.getElementById('birthday');

        function ajustarPorTipo() {
            if (!tipo || !bloqueNacimiento) {
                return;
            }

            const esEmpresa = tipo.value === 'Persona Jurídica';

            bloqueNacimiento.style.display = esEmpresa ? 'none' : '';

            if (esEmpresa && nacimiento) {
                nacimiento.value = '';
            }
        }

        if (tipo) {
            tipo.addEventListener('change', ajustarPorTipo);
            ajustarPorTipo();
        }
    })();
</script>
