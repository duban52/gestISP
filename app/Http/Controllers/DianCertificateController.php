<?php

namespace App\Http\Controllers;

use App\Billing\Dian\SelfSignedCertificate;
use App\Models\Company;
use App\Models\DianCertificate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Carga del certificado digital de firma.
 *
 * ESTO NO ES SUBIR UN ARCHIVO CUALQUIERA
 * --------------------------------------
 * Un `.p12` contiene una **clave privada**. Con ella se firma todo lo
 * que la empresa le presenta a la DIAN, así que quien la tenga puede
 * firmar en su nombre. De ahí las tres reglas que manda este
 * controlador:
 *
 * 1. **Se guarda fuera del directorio público**, en el disco `local`
 *    (`storage/app`), que no es accesible por URL. En la base va solo
 *    la ruta — nunca el contenido: en una columna acabaría también en
 *    cada copia de seguridad y en cada volcado que alguien haga.
 *
 * 2. **No hay ninguna ruta para descargarlo.** Se sube y se usa; no se
 *    devuelve. Un botón de descarga convertiría cualquier sesión
 *    robada en una copia de la clave privada.
 *
 * 3. **La contraseña se cifra y no se devuelve nunca.** Sin ella el
 *    archivo no sirve, así que las dos juntas son la clave; guardarlas
 *    en claro sería guardar la firma de la empresa en texto plano.
 *
 * SE COMPRUEBA QUE ABRA, ANTES DE GUARDAR
 * ---------------------------------------
 * Si la contraseña es incorrecta o el archivo no es un `.p12`, no se
 * guarda nada. Guardar un certificado que no abre significa descubrirlo
 * el día que haya que firmar —normalmente a mitad de la corrida
 * mensual—, y para entonces ya se gastaron consecutivos autorizados.
 *
 * LAS FECHAS SALEN DEL CERTIFICADO, NO DE QUIEN LO SUBE
 * ----------------------------------------------------
 * Se leen de él. Teclearlas a mano permite equivocarse o mentir, y una
 * vigencia falsa hace que el aviso de caducidad llegue tarde — que es
 * justo lo que el aviso existe para evitar.
 */
class DianCertificateController extends Controller
{
    /** Dónde viven los certificados. Fuera de `public`, a propósito. */
    private const CARPETA = 'dian/certificados';

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:dian.manage');
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $request->validate([
            // 'file' y no 'mimes': el .p12 se detecta de forma
            // inconsistente entre sistemas, y el filtro de verdad es
            // que abra con su contraseña, que se comprueba abajo.
            'certificado' => 'required|file|max:5120',
            'password' => 'required|string|max:255',
            'name' => 'nullable|string|max:100',
        ], [
            'certificado.required' => 'Elija el archivo .p12 del certificado.',
            'certificado.max' => 'El certificado no puede pasar de 5 MB.',
        ], [
            'password' => 'contraseña del certificado',
        ]);

        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());
        $leido = [];

        // La comprobacion que de verdad importa: que abra.
        if (!openssl_pkcs12_read($contenido, $leido, $request->input('password'))) {
            return back()->withErrors([
                'certificado' => 'No se pudo abrir el certificado: la contraseña es incorrecta o el archivo no es un .p12 válido.',
            ]);
        }

        $datos = openssl_x509_parse($leido['cert'] ?? '');

        if ($datos === false) {
            return back()->withErrors(['certificado' => 'El certificado no se pudo leer.']);
        }

        // Se guarda con nombre aleatorio: el original puede llevar el
        // NIT o el nombre de quien lo emitio, y un nombre de archivo
        // adivinable es una pista de mas.
        $ruta = Storage::disk('local')->putFileAs(
            self::CARPETA,
            $request->file('certificado'),
            $company->id . '-' . bin2hex(random_bytes(16)) . '.p12',
        );

        // Autofirmado: se anota al cargarlo, no cada vez que se
        // pregunta. Lo comprueba el sistema y no quien sube el archivo
        // porque es precisamente lo que quien lo sube puede no saber —
        // un autofirmado y uno real se ven igual desde fuera.
        $autofirmado = (new SelfSignedCertificate())->esAutofirmado($datos);

        $certificado = DianCertificate::create([
            'company_id' => $company->id,
            'name' => $request->input('name') ?: $this->nombreDe($datos),
            'path' => $ruta,
            'password' => $request->input('password'),
            // Del propio certificado, no de quien lo sube.
            'valid_from' => Carbon::createFromTimestamp($datos['validFrom_time_t'] ?? now()->timestamp),
            'valid_until' => Carbon::createFromTimestamp($datos['validTo_time_t'] ?? now()->timestamp),
            'active' => true,
            'self_signed' => $autofirmado,
        ]);

        // Solo uno activo: dos certificados activos dejarian sin decidir
        // con cual se firma, y esa clase de ambiguedad se descubre
        // tarde.
        DianCertificate::where('company_id', $company->id)
            ->whereKeyNot($certificado->id)
            ->update(['active' => false]);

        $dias = $certificado->diasParaCaducar();

        if ($dias !== null && $dias <= 0) {
            return back()->with('success', 'Certificado cargado, pero está CADUCADO: no sirve para firmar.');
        }

        // Se avisa aquí y no solo en el diagnóstico: quien acaba de
        // subirlo cree que ya está, y descubrirlo el día de emitir es
        // descubrirlo tarde.
        if ($autofirmado) {
            return back()->with(
                'success',
                'Certificado cargado, pero está AUTOFIRMADO: sirve para probar la firma, '
                . 'no para emitir. La DIAN solo acepta certificados de entidades acreditadas por la ONAC.',
            );
        }

        return back()->with(
            'success',
            sprintf('Certificado cargado. Vigente hasta el %s.', $certificado->valid_until->format('d/m/Y')),
        );
    }

    /**
     * Desactiva un certificado.
     *
     * NO SE BORRA EL ARCHIVO
     * ----------------------
     * Con él se firmaron documentos ya transmitidos, y poder demostrar
     * con qué certificado se firmó cada uno es parte de poder
     * explicarlos. Lo que se quita es su uso.
     */
    public function destroy(Company $company, DianCertificate $certificate): RedirectResponse
    {
        abort_unless($certificate->company_id === $company->id, 404);

        $certificate->update(['active' => false]);

        return back()->with('success', 'Certificado desactivado. Ya no se firmará con él.');
    }

    /** El nombre del titular, para no dejarlo sin etiqueta. */
    private function nombreDe(array $datos): string
    {
        $sujeto = $datos['subject'] ?? [];

        return (string) ($sujeto['CN'] ?? $sujeto['O'] ?? 'Certificado');
    }
}
