{{-- ============================================================
     Visor de PDF en la misma página

     Modal único y reutilizable: cualquier botón con
     data-pdf-url="{{ route('technicals_orders.pdf', $id) }}" abre
     aquí el comprobante en un iframe, sin salir de la vista.

     El JS que lo activa vive en el @section('js') de cada página
     (no aquí) para garantizar que jQuery ya esté cargado.
     ============================================================ --}}
<div class="modal fade" id="pdfViewerModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title mb-0">
                    <i class="fas fa-file-pdf mr-1"></i> Comprobante de la orden
                </h5>
                <div class="ml-auto d-flex align-items-center">
                    <a href="#" id="pdfViewerDownload" class="btn btn-light btn-sm" target="_blank"
                       title="Abrir en una pestaña / descargar">
                        <i class="fas fa-external-link-alt mr-1"></i> Abrir / Descargar
                    </a>
                    <button type="button" class="close text-white ml-3" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            </div>
            <div class="modal-body p-0">
                <iframe id="pdfViewerFrame" src="" title="Comprobante PDF"
                        style="width: 100%; height: 80vh; border: 0;"></iframe>
            </div>
        </div>
    </div>
</div>
