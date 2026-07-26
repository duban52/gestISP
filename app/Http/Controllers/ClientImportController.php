<?php

namespace App\Http\Controllers;

use App\Services\Import\ClientContractImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importación masiva de clientes y contratos.
 *
 * Pensada para poner en marcha el sistema con la cartera que ya venía
 * de otro software. El archivo se sube una vez y siempre se REVISA
 * antes de escribir: primero se muestra qué se va a crear y qué filas
 * tienen problemas, y solo entonces se confirma.
 *
 * Se protege con su propio permiso (clients.import) para poder
 * concederla únicamente a quien corresponda desde el módulo de roles.
 */
class ClientImportController extends Controller
{
    /** Carpeta temporal donde espera el archivo entre la revisión y la importación. */
    private const CARPETA = 'imports/clientes';

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:clients.import');
    }

    /**
     * Pantalla inicial: instrucciones y formulario de carga.
     */
    public function index(): View
    {
        return view('gestisp.clients.import.index', [
            'campos' => $this->camposDocumentados(),
        ]);
    }

    /**
     * Sube el archivo y muestra qué ocurriría, SIN escribir nada.
     */
    public function preview(Request $request, ClientContractImporter $importador): View|RedirectResponse
    {
        $request->validate([
            'archivo' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ], [
            'archivo.required' => 'Seleccione el archivo que desea importar.',
            'archivo.mimes' => 'El archivo debe ser CSV o Excel (xlsx, xls).',
            'archivo.max' => 'El archivo no puede pesar más de 10 MB.',
        ]);

        // Se guarda para no obligar a subirlo otra vez al confirmar
        $ruta = $request->file('archivo')->store(self::CARPETA);

        try {
            $resultado = $importador->previsualizar(
                Storage::path($ruta),
                (int) session('branch_id'),
            );
        } catch (\Throwable $e) {
            Storage::delete($ruta);

            return back()->with('error', 'No se pudo leer el archivo: ' . $e->getMessage());
        }

        if ($resultado['resumen']['total'] === 0) {
            Storage::delete($ruta);

            return back()->with('error', 'El archivo no tiene filas con datos.');
        }

        return view('gestisp.clients.import.preview', [
            'ruta' => $ruta,
            'nombreArchivo' => $request->file('archivo')->getClientOriginalName(),
            'filas' => $resultado['filas'],
            'resumen' => $resultado['resumen'],
            'campos' => $this->camposDocumentados(),
        ]);
    }

    /**
     * Importa definitivamente el archivo ya revisado.
     */
    public function store(Request $request, ClientContractImporter $importador): RedirectResponse
    {
        $request->validate([
            'ruta' => 'required|string',
            'crear_saldos' => 'nullable|boolean',
        ]);

        $ruta = $request->input('ruta');

        // El nombre viaja en el formulario: se comprueba que apunte de
        // verdad a un archivo subido en esta pantalla.
        if (!str_starts_with($ruta, self::CARPETA . '/') || !Storage::exists($ruta)) {
            return redirect()->route('clients.import.index')
                ->with('error', 'El archivo ya no está disponible. Vuelva a subirlo.');
        }

        try {
            $resultado = $importador->importar(
                Storage::path($ruta),
                (int) session('branch_id'),
                $request->boolean('crear_saldos', true),
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'La importación falló: ' . $e->getMessage());
        } finally {
            Storage::delete($ruta);
        }

        return redirect()->route('clients.import.index')
            ->with('resultadoImportacion', $resultado)
            ->with('success', sprintf(
                'Importación terminada: %d contrato(s) creado(s), %d cliente(s) nuevo(s).',
                $resultado['creados'],
                $resultado['clientes_nuevos'],
            ));
    }

    /**
     * Descarga una plantilla de ejemplo con los encabezados correctos.
     */
    public function plantilla(): StreamedResponse
    {
        $encabezados = [
            'Numero de contrato', 'Documento', 'Tipo documento', 'Nombre', 'Apellido',
            'Telefono', 'Telefono adicional', 'Correo', 'Plan', 'Direccion', 'Barrio',
            'Municipio', 'Departamento', 'Estado', 'Fecha activacion', 'Usuario PPPoE',
            'Clave PPPoE', 'Serial ONT', 'SSID', 'Clave WiFi', 'Saldo pendiente',
        ];

        $ejemplo = [
            'ENG000123', '1234567890', 'Cédula de ciudadanía', 'Ana María', 'Restrepo Gómez',
            '3155554433', '', 'ana@ejemplo.com', 'Internet 100 Megas', 'Calle 10 # 20-30',
            'El Centro', 'Gómez Plata', 'Antioquia', 'Activo', '15/03/2024', 'ana.restrepo',
            'clave123', 'HWTC12345678', 'WiFi-Ana', 'micontrasena', '85000',
        ];

        return response()->streamDownload(function () use ($encabezados, $ejemplo) {
            $salida = fopen('php://output', 'w');

            // BOM para que Excel abra los acentos correctamente
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, $encabezados, ';');
            fputcsv($salida, $ejemplo, ';');

            fclose($salida);
        }, 'plantilla-importacion-clientes.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Columnas que entiende el importador, para mostrarlas en pantalla.
     *
     * @return array<int, array{campo: string, titulos: string, obligatorio: bool, destino: string}>
     */
    private function camposDocumentados(): array
    {
        $obligatorios = ['identity_number', 'name', 'plan'];

        $etiquetas = [
            'identity_number' => ['Documento del cliente', 'cliente'],
            'type_document' => ['Tipo de documento', 'cliente'],
            'name' => ['Nombre', 'cliente'],
            'last_name' => ['Apellido', 'cliente'],
            'type_client' => ['Tipo de cliente', 'cliente'],
            'number_phone' => ['Teléfono', 'cliente'],
            'aditional_phone' => ['Teléfono adicional', 'cliente'],
            'email' => ['Correo electrónico', 'cliente'],
            'birthday' => ['Fecha de nacimiento', 'cliente'],
            'contract_number' => ['Número de contrato (se respeta el del sistema anterior)', 'contrato'],
            'plan' => ['Plan (nombre o identificador)', 'contrato'],
            'address' => ['Dirección del servicio', 'contrato'],
            'neighborhood' => ['Barrio', 'contrato'],
            'municipality' => ['Municipio', 'contrato'],
            'department' => ['Departamento', 'contrato'],
            'home_type' => ['Tipo de vivienda', 'contrato'],
            'social_stratum' => ['Estrato', 'contrato'],
            'status' => ['Estado del contrato', 'contrato'],
            'activation_date' => ['Fecha de activación', 'contrato'],
            'user_pppoe' => ['Usuario PPPoE', 'contrato'],
            'password_pppoe' => ['Clave PPPoE', 'contrato'],
            'cpe_sn' => ['Serial del equipo', 'contrato'],
            'ssid_wifi' => ['Nombre de la red WiFi', 'contrato'],
            'password_wifi' => ['Clave del WiFi', 'contrato'],
            'nap_port' => ['Puerto NAP', 'contrato'],
            'saldo' => ['Saldo pendiente', 'facturación'],
        ];

        // Títulos que acepta cada campo, sacados del propio importador
        $porCampo = [];

        foreach (ClientContractImporter::equivalencias() as $titulo => $campo) {
            $porCampo[$campo][] = $titulo;
        }

        $filas = [];

        foreach ($etiquetas as $campo => [$descripcion, $destino]) {
            $filas[] = [
                'campo' => $descripcion,
                'titulos' => implode(', ', array_slice($porCampo[$campo] ?? [], 0, 4)),
                'obligatorio' => in_array($campo, $obligatorios, true),
                'destino' => $destino,
            ];
        }

        return $filas;
    }
}
