<?php

namespace App\Console\Commands;

use App\Billing\Dian\SelfSignedCertificate;
use App\Models\Company;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Genera un certificado AUTOFIRMADO para probar el flujo completo.
 *
 *     php artisan dian:certificado-de-pruebas --empresa=7
 *
 * PARA QUÉ ES
 * -----------
 * Para no quedarse parado esperando el certificado de verdad. Con este
 * se puede emitir una factura y verla firmada, con su QR y su XML,
 * pasando por exactamente el mismo código que pasará el día que haya
 * uno real. Todo el camino queda ejercitado menos el último tramo.
 *
 * PARA QUÉ NO ES
 * --------------
 * Para emitir. La DIAN exige que el certificado encadene contra una
 * entidad acreditada por la ONAC, y este se firma a sí mismo. Lo que
 * se le mande firmado con él lo va a rechazar.
 *
 * Eso, por cierto, también lo hace útil: mandar el set de pruebas
 * firmado así y ver qué contesta la DIAN cuesta un minuto y responde de
 * primera mano una pregunta que si no hay que ir a buscar.
 *
 * LOS TRES CANDADOS
 * -----------------
 * Un certificado de pruebas olvidado en una empresa que ya factura de
 * verdad es un accidente caro: se firmaría todo el mes con él y la DIAN
 * lo rechazaría todo. Así que:
 *
 *   1. **Se niega si la empresa está en ambiente de producción.**
 *   2. Queda marcado en la base (`self_signed`) y en su nombre.
 *   3. El diagnóstico y el panel lo señalan en rojo como bloqueante.
 *
 * LA CONTRASEÑA NO LA ELIGE NADIE
 * -------------------------------
 * Se genera al azar y se guarda cifrada, igual que la de un certificado
 * real. No se enseña porque no hace falta: el sistema es el único que
 * abre el archivo. Una contraseña tecleada aquí acabaría en el
 * historial de la consola.
 */
class GenerateTestCertificate extends Command
{
    protected $signature = 'dian:certificado-de-pruebas
                            {--empresa= : Id de la empresa}
                            {--dias=365 : Vigencia del certificado}';

    protected $description = 'Genera un certificado autofirmado para probar la firma (la DIAN NO lo acepta)';

    /** Dónde viven los certificados. La misma carpeta que los de verdad. */
    private const CARPETA = 'dian/certificados';

    public function handle(SelfSignedCertificate $generador): int
    {
        $empresa = $this->empresa();

        if (!$empresa) {
            return self::FAILURE;
        }

        // ---- Candado 1: nunca en producción ----
        //
        // Aquí no se avisa y se sigue: se para. En producción la firma
        // no es una prueba, es lo que sostiene cada factura emitida.
        $configuracion = DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->first();

        // Se mira el AMBIENTE pelado y no `estaEnProduccion()`, que
        // además exige `enabled_at`. Aquí conviene ser más estricto:
        // una empresa puesta en producción a la que aún no se le ha
        // anotado la habilitación sigue siendo un sitio donde esto no
        // pinta nada.
        if ($configuracion?->environment_code === DianConfiguration::PRODUCCION) {
            $this->error(sprintf(
                '«%s» está en ambiente de PRODUCCIÓN. Un certificado de pruebas no se genera ahí: '
                . 'la DIAN rechazaría todo lo que se firmara con él.',
                $empresa->nombreVisible(),
            ));

            return self::FAILURE;
        }

        $dias = max(1, (int) $this->option('dias'));

        try {
            $p12 = $generador->generar($this->identidadDe($empresa), $clave = $this->claveAlAzar(), $dias);
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        // Nombre de archivo al azar, como el de los de verdad: uno
        // adivinable es una pista de más.
        $ruta = self::CARPETA . '/' . $empresa->id . '-pruebas-' . bin2hex(random_bytes(16)) . '.p12';
        Storage::disk('local')->put($ruta, $p12);

        $certificado = DianCertificate::create([
            'company_id' => $empresa->id,
            'name' => 'PRUEBAS — autofirmado (' . $empresa->nombreVisible() . ')',
            'path' => $ruta,
            'password' => $clave,
            'valid_from' => now()->startOfDay(),
            'valid_until' => now()->startOfDay()->addDays($dias),
            'active' => true,
            // Candado 2: queda dicho en la base, no solo en el nombre.
            'self_signed' => true,
        ]);

        // Solo uno activo, igual que al cargar uno real: dos activos
        // dejarían sin decidir con cuál se firma.
        DianCertificate::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->whereKeyNot($certificado->id)
            ->update(['active' => false]);

        $this->info(sprintf('Certificado de pruebas generado para «%s».', $empresa->nombreVisible()));
        $this->line('  Vigencia: ' . $certificado->valid_until->format('d/m/Y') . sprintf(' (%d días)', $dias));
        $this->newLine();
        $this->warn('La DIAN NO acepta este certificado: está autofirmado.');
        $this->line('Sirve para ver el flujo completo —XML, firma, QR, transmisión— mientras llega el de verdad.');
        $this->line('Cárguelo desde el panel DIAN en cuanto lo tenga: al hacerlo, este se desactiva solo.');

        return self::SUCCESS;
    }

    /**
     * La empresa sobre la que se trabaja.
     *
     * Se exige decir cuál cuando hay varias. Generar un certificado en
     * la empresa equivocada es de los errores que no se ven hasta que
     * algo se firma mal.
     */
    private function empresa(): ?Company
    {
        if ($id = $this->option('empresa')) {
            $empresa = Company::withoutGlobalScopes()->find($id);

            if (!$empresa) {
                $this->error('No existe ninguna empresa con el id ' . $id . '.');
            }

            return $empresa;
        }

        $empresas = Company::withoutGlobalScopes()->orderBy('legal_name')->get();

        if ($empresas->count() === 1) {
            return $empresas->first();
        }

        if ($empresas->isEmpty()) {
            $this->error('No hay ninguna empresa.');

            return null;
        }

        $this->error('Hay varias empresas: indique cuál con --empresa=');

        foreach ($empresas as $una) {
            $this->line(sprintf('  %d  %s', $una->id, $una->nombreVisible()));
        }

        return null;
    }

    /**
     * El sujeto del certificado: la empresa de verdad.
     *
     * Podría ser cualquier cosa —nadie lo valida— pero poner los datos
     * reales hace que el XML firmado se parezca al que se emitirá de
     * verdad, y eso es justo lo que se quiere poder mirar.
     *
     * @return array<string, string>
     */
    private function identidadDe(Company $empresa): array
    {
        return array_filter([
            'countryName' => 'CO',
            'organizationName' => $empresa->legal_name ?: $empresa->nombreVisible(),
            'commonName' => $empresa->nombreVisible(),
            'serialNumber' => $empresa->identificacion(),
            'emailAddress' => $empresa->email,
        ]);
    }

    /** Al azar y larga: nadie la teclea, así que no hay por qué acortarla. */
    private function claveAlAzar(): string
    {
        return bin2hex(random_bytes(16));
    }
}
