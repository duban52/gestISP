{{-- ============================================================
     Lista de permisos agrupada por módulo (parcial compartido
     entre crear y editar rol).

     Espera:
      - $permissionGroups: permisos agrupados por módulo
        (RoleController::permissionsByModule()).
      - $checkedPermissions: array de ids de permisos que deben
        aparecer marcados.

     Cada tarjeta tiene un checkbox de módulo que marca/desmarca
     todos sus permisos; los botones superiores afectan a todos.
     ============================================================ --}}
@php
    $checkedPermissions = $checkedPermissions ?? [];
@endphp

<h5 class="mt-4">Permisos del rol</h5>

<div class="mb-3">
    <button type="button" id="select-all-permissions" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-check-double"></i> Seleccionar todos
    </button>
    <button type="button" id="deselect-all-permissions" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-times"></i> Quitar todos
    </button>
</div>

<div class="row">
    @foreach($permissionGroups as $module => $permissions)
        <div class="col-md-4 mb-3">
            <div class="card h-100">
                {{-- Checkbox de módulo: marca/desmarca todo el grupo --}}
                <div class="card-header py-2">
                    <label class="mb-0">
                        <input type="checkbox" class="mr-1 check-module">
                        <strong>{{ $module }}</strong>
                    </label>
                </div>
                <div class="card-body py-2">
                    @foreach($permissions as $permission)
                        <div class="form-check">
                            <label class="form-check-label">
                                <input type="checkbox"
                                       class="form-check-input permission-checkbox"
                                       name="permissions[]"
                                       value="{{ $permission->id }}"
                                       {{ in_array($permission->id, $checkedPermissions) ? 'checked' : '' }}>
                                {{ $permission->description ?? $permission->name }}
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- El script va inline (vanilla JS, sin dependencias) para que el
     parcial sea autocontenido en ambas vistas. --}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        /**
         * Marca el checkbox de cada módulo cuando todos sus
         * permisos están seleccionados.
         */
        function refreshModuleChecks() {
            document.querySelectorAll('.check-module').forEach(function (moduleCheck) {
                const boxes = moduleCheck.closest('.card').querySelectorAll('.permission-checkbox');
                moduleCheck.checked = boxes.length > 0 &&
                    Array.from(boxes).every(cb => cb.checked);
            });
        }

        // Checkbox de módulo: marca/desmarca todos los permisos del grupo
        document.querySelectorAll('.check-module').forEach(function (moduleCheck) {
            moduleCheck.addEventListener('change', function () {
                moduleCheck.closest('.card')
                    .querySelectorAll('.permission-checkbox')
                    .forEach(cb => cb.checked = moduleCheck.checked);
            });
        });

        // Cada permiso individual actualiza el estado de su módulo
        document.querySelectorAll('.permission-checkbox').forEach(function (cb) {
            cb.addEventListener('change', refreshModuleChecks);
        });

        // Botones globales
        document.getElementById('select-all-permissions').addEventListener('click', function () {
            document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = true);
            refreshModuleChecks();
        });

        document.getElementById('deselect-all-permissions').addEventListener('click', function () {
            document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = false);
            refreshModuleChecks();
        });

        refreshModuleChecks();
    });
</script>
