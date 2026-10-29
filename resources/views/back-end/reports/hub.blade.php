@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0">Reports</h4>
        <small class="text-muted">Every report you can open. Each one has a date range and branch filter where it applies.</small>
    </div>
    <div class="row">
        @foreach($groups as $group => $items)
            <div class="col-xl-4 col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header"><h6 class="m-0">{{ $group }}</h6></div>
                    <div class="list-group list-group-flush">
                        @foreach($items as [$route, $label, $description])
                            <a href="{{ route($route) }}" class="list-group-item list-group-item-action">
                                <div class="font-weight-bold">{{ $label }}</div>
                                <small class="text-muted">{{ $description }}</small>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
