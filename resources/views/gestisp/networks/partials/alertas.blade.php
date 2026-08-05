{{-- Alertas compartidas por todas las pantallas del módulo. --}}
@if(session('success'))
    <div class="alert alert-success alert-dismissible shadow-sm">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible shadow-sm">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('error') }}
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger shadow-sm">
        <strong>Revise los datos:</strong>
        <ul class="mb-0 mt-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
