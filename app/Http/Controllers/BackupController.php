<?php

namespace App\Http\Controllers;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupRepository;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Copias de seguridad de la base de datos (solo superadministrador).
 *
 * Esta pantalla NO es el plan de respaldo: el plan son las dos copias
 * diarias que el cron envía a la NAS (ver
 * docs/Manual_Copias_Seguridad_GestISP.md). Lo que hay aquí es la copia
 * bajo demanda, para el momento concreto en que alguien quiere una foto
 * de la base ANTES de tocar algo delicado —una migración, una carga
 * masiva, un cambio de tarifas— y llevársela consigo.
 *
 * Está reservada al superadministrador, igual que la trazabilidad, y
 * por el mismo motivo elevado a la enésima potencia: el archivo que se
 * descarga contiene la base de datos ENTERA —todos los clientes, sus
 * documentos, sus contraseñas PPPoE y el histórico de pagos—. No es un
 * permiso que pueda concederse marcando una casilla en el módulo de
 * roles.
 */
class BackupController extends Controller
{
    /**
     * Evita dos volcados simultáneos.
     *
     * Sin esto, dos clics seguidos al botón lanzarían dos mysqldump a
     * la vez sobre la misma base: el doble de carga en el servidor,
     * justo cuando está atendiendo a los usuarios.
     */
    private const CANDADO = 'gestisp:backup:en-curso';

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('superadmin');
    }

    /**
     * Listado de copias disponibles en el servidor y estado del
     * respaldo automático.
     */
    public function index(BackupRepository $repositorio): View
    {
        $copias = $repositorio->todas();
        $ultimaAutomatica = $copias->firstWhere('origen', BackupFile::ORIGEN_AUTOMATICO);
        $horasDeAviso = (int) config('backup.stale_after_hours', 15);

        return view('gestisp.backups.index', [
            'copias' => $copias,
            'ultimaAutomatica' => $ultimaAutomatica,
            // El respaldo automático se da por atrasado cuando la
            // última copia del cron es más vieja de lo previsto, y
            // también cuando no hay NINGUNA: eso significa que el cron
            // nunca llegó a instalarse.
            'automaticoAtrasado' => $ultimaAutomatica === null
                || $ultimaAutomatica->horasDeAntiguedad() > $horasDeAviso,
            'horasDeAviso' => $horasDeAviso,
            // Ya formateados: la vista muestra tamaños, no hace cuentas
            'espacioOcupado' => BackupFile::formatearTamano($repositorio->espacioOcupado()),
            'espacioLibre' => ($libre = $repositorio->espacioLibre()) === null
                ? null
                : BackupFile::formatearTamano($libre),
            'diasQueSeConservan' => (int) config('backup.keep_days', 7),
        ]);
    }

    /**
     * Genera una copia en el momento.
     */
    public function store(DatabaseBackup $respaldo): RedirectResponse
    {
        $candado = Cache::lock(self::CANDADO, (int) config('backup.timeout', 900));

        if (!$candado->get()) {
            return redirect()
                ->route('backups.index')
                ->with('warning', 'Ya hay una copia generándose en este momento. Espere a que termine.');
        }

        // El volcado puede tardar varios minutos en una base grande y
        // el límite normal de PHP son 30 segundos. El proceso ya tiene
        // su propio tiempo máximo (config/backup.timeout), así que no
        // se queda colgado para siempre.
        set_time_limit(0);

        try {
            $copia = $respaldo->generar(BackupFile::ORIGEN_MANUAL);
        } catch (BackupException $e) {
            return redirect()
                ->route('backups.index')
                ->with('error', 'No se pudo generar la copia. ' . $e->getMessage());
        } finally {
            $candado->release();
        }

        return redirect()
            ->route('backups.index')
            // El nombre viaja en la sesión para que la pantalla pueda
            // ofrecer la descarga de ESTA copia recién hecha y no
            // haya que buscarla en el listado
            ->with('copia_generada', $copia->nombre)
            ->with('success', sprintf(
                'Copia generada correctamente: %s (%s).',
                $copia->nombre,
                $copia->tamanoLegible(),
            ));
    }

    /**
     * Descarga una copia.
     *
     * El nombre llega por la URL, así que se resuelve siempre a través
     * del repositorio, que es quien valida que sea realmente una de
     * nuestras copias y que esté dentro de la carpeta.
     */
    public function download(string $archivo, BackupRepository $repositorio): BinaryFileResponse
    {
        $copia = $repositorio->buscar($archivo);

        abort_if($copia === null, 404, 'La copia de seguridad solicitada ya no existe.');

        return response()->download($copia->ruta, $copia->nombre, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    /**
     * Elimina una copia del servidor.
     *
     * Solo borra la copia LOCAL. Lo que ya se envió a la NAS sigue
     * allí: esa es la copia que de verdad protege, y no se toca desde
     * la aplicación a propósito.
     */
    public function destroy(string $archivo, BackupRepository $repositorio): RedirectResponse
    {
        if (!$repositorio->borrar($archivo)) {
            return redirect()
                ->route('backups.index')
                ->with('error', 'No se encontró esa copia en el servidor.');
        }

        return redirect()
            ->route('backups.index')
            ->with('success', 'Copia eliminada del servidor. La que se envió a la NAS no se ha tocado.');
    }
}
