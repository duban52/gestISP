<?php

namespace Tests\Feature\System;

use App\Models\Audit;
use App\Models\Branch;
use App\Models\User;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupRepository;
use App\Services\Backup\DatabaseBackup;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Copias de seguridad de la base de datos.
 *
 * Lo que se comprueba aquí es, sobre todo, que la carpeta de copias no
 * se convierta en una puerta trasera: el nombre del archivo llega por
 * la URL y dentro de esa carpeta hay volcados con los datos de todos
 * los clientes.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $superadmin;
    private Role $rolSuper;
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Carpeta propia de las pruebas: nunca se toca la del servidor
        $this->carpeta = storage_path('framework/testing/backups');
        File::deleteDirectory($this->carpeta);
        File::ensureDirectoryExists($this->carpeta);
        config(['backup.path' => $this->carpeta]);

        $this->branch = Branch::factory()->create();
        $this->rolSuper = Role::where('name', 'superadministrador')->firstOrFail();

        $this->superadmin = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $this->superadmin->assignRole($this->rolSuper);
        $this->superadmin->branches()->attach($this->branch->id, ['role_id' => $this->rolSuper->id]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);

        parent::tearDown();
    }

    private function comoSuperadmin(): self
    {
        $this->actingAs($this->superadmin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rolSuper->id,
        ]);

        return $this;
    }

    /** Deja un archivo con pinta de copia, sin ejecutar mysqldump. */
    private function copiaFalsa(string $nombre, string $contenido = 'contenido'): string
    {
        $ruta = $this->carpeta . DIRECTORY_SEPARATOR . $nombre;
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    // ==================== Acceso ====================

    public function test_la_pantalla_es_exclusiva_del_superadministrador(): void
    {
        $rolAdmin = Role::where('name', 'administrador')->firstOrFail();

        $usuario = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $usuario->assignRole($rolAdmin);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rolAdmin->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rolAdmin->id,
        ]);

        $this->get(route('backups.index'))->assertForbidden();
        $this->post(route('backups.store'))->assertForbidden();
    }

    public function test_el_superadministrador_ve_las_copias_del_servidor(): void
    {
        $this->copiaFalsa('gestisp-db-2026-08-09_023000-auto.sql.gz');
        $this->copiaFalsa('gestisp-db-2026-08-10_101500-manual.sql.gz');

        $this->comoSuperadmin()
            ->get(route('backups.index'))
            ->assertOk()
            ->assertSee('gestisp-db-2026-08-10_101500-manual.sql.gz')
            ->assertSee('gestisp-db-2026-08-09_023000-auto.sql.gz');
    }

    // ==================== Descarga ====================

    public function test_descarga_una_copia_existente(): void
    {
        $this->copiaFalsa('gestisp-db-2026-08-10_101500-manual.sql.gz', 'volcado');

        $this->comoSuperadmin()
            ->get(route('backups.download', 'gestisp-db-2026-08-10_101500-manual.sql.gz'))
            ->assertOk()
            ->assertDownload('gestisp-db-2026-08-10_101500-manual.sql.gz');
    }

    public function test_la_descarga_queda_registrada_en_la_trazabilidad(): void
    {
        $this->copiaFalsa('gestisp-db-2026-08-10_101500-manual.sql.gz');

        $this->comoSuperadmin()
            ->get(route('backups.download', 'gestisp-db-2026-08-10_101500-manual.sql.gz'))
            ->assertOk();

        $registro = Audit::where('action', 'backups.download')->first();

        $this->assertNotNull($registro, 'La descarga de la copia no quedó registrada.');
        $this->assertSame('sistema', $registro->category);
        $this->assertStringContainsString('gestisp-db-2026-08-10_101500-manual.sql.gz', $registro->description);
    }

    /**
     * El caso que justifica que toda la carpeta se acceda por el
     * repositorio: quien encuentre esta URL intentará salirse de ella.
     *
     * @dataProvider nombresQueDebenRechazarse
     */
    public function test_rechaza_cualquier_nombre_que_no_sea_una_copia(string $nombre): void
    {
        $this->comoSuperadmin()
            ->get('/copias-de-seguridad/' . $nombre . '/descargar')
            ->assertNotFound();
    }

    public static function nombresQueDebenRechazarse(): array
    {
        return [
            'sube de carpeta' => ['..%2F..%2F..%2F.env'],
            'archivo del sistema' => ['.env'],
            'otro archivo de la carpeta' => ['credenciales.txt'],
            'nombre casi válido' => ['gestisp-db-2026-08-10_101500-otro.sql.gz'],
            'copia inexistente' => ['gestisp-db-1999-01-01_000000-auto.sql.gz'],
        ];
    }

    public function test_no_entrega_un_archivo_ajeno_aunque_este_en_la_carpeta(): void
    {
        // Un .env que alguien dejó por descuido junto a las copias
        file_put_contents($this->carpeta . DIRECTORY_SEPARATOR . '.env', 'DB_PASSWORD=secreto');

        $this->comoSuperadmin()
            ->get('/copias-de-seguridad/.env/descargar')
            ->assertNotFound();

        $this->comoSuperadmin()
            ->get(route('backups.index'))
            ->assertOk()
            ->assertDontSee('DB_PASSWORD');
    }

    // ==================== Eliminación ====================

    public function test_elimina_una_copia_del_servidor(): void
    {
        $ruta = $this->copiaFalsa('gestisp-db-2026-08-10_101500-manual.sql.gz');

        $this->comoSuperadmin()
            ->delete(route('backups.destroy', 'gestisp-db-2026-08-10_101500-manual.sql.gz'))
            ->assertRedirect(route('backups.index'));

        $this->assertFileDoesNotExist($ruta);
    }

    // ==================== Retención ====================

    public function test_la_retencion_borra_las_viejas_pero_nunca_baja_del_minimo(): void
    {
        // Seis copias, todas más antiguas que el periodo de retención
        foreach ([1, 2, 3, 4, 5, 6] as $dia) {
            $this->copiaFalsa(sprintf('gestisp-db-2026-01-0%d_023000-auto.sql.gz', $dia));
        }

        $repositorio = app(BackupRepository::class);
        $resultado = $repositorio->purgar(dias: 7, minimo: 4);

        $this->assertSame(2, $resultado['borradas']);
        $this->assertCount(4, $repositorio->todas());

        // Las que sobreviven son las MÁS RECIENTES
        $this->assertSame(
            'gestisp-db-2026-01-06_023000-auto.sql.gz',
            $repositorio->todas()->first()->nombre,
        );
    }

    public function test_conserva_las_copias_dentro_del_periodo(): void
    {
        $this->copiaFalsa('gestisp-db-' . now()->format('Y-m-d_His') . '-auto.sql.gz');
        $this->copiaFalsa('gestisp-db-' . now()->subDay()->format('Y-m-d_His') . '-auto.sql.gz');

        $repositorio = app(BackupRepository::class);
        $resultado = $repositorio->purgar(dias: 7, minimo: 1);

        $this->assertSame(0, $resultado['borradas']);
    }

    // ==================== Volcado real ====================

    /**
     * Genera una copia de verdad contra la base de pruebas.
     *
     * Se salta cuando el equipo no tiene mysqldump (por ejemplo en un
     * Windows sin el cliente de MySQL en el PATH): no tiene sentido
     * dar por fallida una prueba por una herramienta ausente, pero sí
     * ejecutarla donde exista, que es donde importa.
     */
    public function test_genera_un_volcado_valido_y_verificado(): void
    {
        if (!$this->hayMysqldump()) {
            $this->markTestSkipped('mysqldump no está disponible en este equipo.');
        }

        $copia = app(DatabaseBackup::class)->generar(BackupFile::ORIGEN_MANUAL);

        $this->assertFileExists($copia->ruta);
        $this->assertGreaterThan(0, $copia->bytes);
        $this->assertSame(BackupFile::ORIGEN_MANUAL, $copia->origen);

        // El archivo tiene que ser un gzip legible y contener la marca
        // de cierre: es exactamente lo que distingue una copia buena
        // de una truncada
        $contenido = gzfile($copia->ruta);
        $this->assertNotFalse($contenido);
        $this->assertStringContainsString('Dump completed', implode('', array_slice($contenido, -5)));
    }

    private function hayMysqldump(): bool
    {
        $proceso = new Process([(string) config('backup.mysqldump', 'mysqldump'), '--version']);
        $proceso->run();

        return $proceso->isSuccessful();
    }
}
