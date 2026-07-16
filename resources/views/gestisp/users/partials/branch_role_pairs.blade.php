{{-- ============================================================
     Asignación de sucursales y roles (parcial compartido entre
     crear y editar usuario).

     Espera:
      - $branchPairs: array de pares ['branch_id' => ?, 'role_id' => ?]
        con las asignaciones a renderizar (old() o las actuales).
      - $branches: catálogo de sucursales.
      - $roles: catálogo de roles.

     El botón "Agregar otra sucursal" clona el bloque con JS usando
     un <template>, así el HTML de la fila existe una sola vez.
     ============================================================ --}}

<div id="branch-role-container">
    @foreach($branchPairs as $index => $pair)
        <div class="branch-role-pair mb-3">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Sucursal</label>
                        <select class="form-control branch-select" name="branches[{{ $index }}][branch_id]" required>
                            <option value="">Seleccione</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}"
                                    {{ (string) $branch->id === (string) ($pair['branch_id'] ?? '') ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="mb-3">
                        <label class="form-label">Rol</label>
                        <select class="form-control role-select" name="branches[{{ $index }}][role_id]" required>
                            <option value="">Seleccione</option>
                            @foreach($roles as $rol)
                                <option value="{{ $rol->id }}"
                                    {{ (string) $rol->id === (string) ($pair['role_id'] ?? '') ? 'selected' : '' }}>
                                    {{ $rol->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger remove-branch-role" style="margin-top: 30px;"
                            title="Quitar asignación">X</button>
                </div>
            </div>
        </div>
    @endforeach
</div>

<button type="button" id="add-branch-role" class="btn btn-primary">
    <i class="fas fa-plus"></i> Agregar otra sucursal
</button>

{{-- Plantilla de una fila nueva. __INDEX__ se reemplaza por el
     índice consecutivo al clonar. --}}
<template id="branch-role-template">
    <div class="branch-role-pair mb-3">
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Sucursal</label>
                    <select class="form-control branch-select" name="branches[__INDEX__][branch_id]" required>
                        <option value="">Seleccione</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-md-5">
                <div class="mb-3">
                    <label class="form-label">Rol</label>
                    <select class="form-control role-select" name="branches[__INDEX__][role_id]" required>
                        <option value="">Seleccione</option>
                        @foreach($roles as $rol)
                            <option value="{{ $rol->id }}">{{ $rol->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-danger remove-branch-role" style="margin-top: 30px;"
                        title="Quitar asignación">X</button>
            </div>
        </div>
    </div>
</template>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Índice consecutivo para los name de los inputs nuevos
        let pairIndex = document.querySelectorAll('#branch-role-container .branch-role-pair').length;

        // Agregar una nueva fila desde la plantilla
        document.getElementById('add-branch-role').addEventListener('click', function () {
            const template = document.getElementById('branch-role-template');
            const html = template.innerHTML.replaceAll('__INDEX__', pairIndex);

            document.getElementById('branch-role-container')
                .insertAdjacentHTML('beforeend', html);

            pairIndex++;
        });

        // Quitar una fila (siempre debe quedar al menos una)
        document.addEventListener('click', function (event) {
            if (event.target.classList.contains('remove-branch-role')) {
                const pairs = document.querySelectorAll('#branch-role-container .branch-role-pair');

                if (pairs.length <= 1) {
                    alert('El usuario debe tener al menos una sucursal asignada.');
                    return;
                }

                event.target.closest('.branch-role-pair').remove();
            }
        });
    });
</script>
