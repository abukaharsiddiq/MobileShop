@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Branch-wise Stock</h5>
            <form method="GET" class="form-inline">
                <input type="text" name="q" class="form-control form-control-sm mr-2" value="{{ request('q') }}" placeholder="Product, model or SKU">
                <label class="small mr-2"><input type="checkbox" name="all" value="1" @checked(request('all'))> show zero stock</label>
                <button class="btn btn-sm btn-primary">Search</button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-bordered table-hover">
                <thead class="thead-light">
                    <tr><th>Product / variant</th>
                        @foreach($matrix['branches'] as $b)<th class="text-right">{{ $b->name }}{!! $b->isWarehouse() ? '<br><small>warehouse</small>' : '' !!}</th>@endforeach
                        @if($matrix['transit'])<th class="text-right text-info">In transit</th>@endif
                        <th class="text-right">Total</th></tr>
                </thead>
                <tbody>
                    @forelse($matrix['stocks'] as $s)
                        <tr>
                            <td>{{ $s->variantLabel() }}</td>
                            @foreach($matrix['branches'] as $b)
                                @php $q = $s->by_branch[$b->id] ?? 0; @endphp
                                <td class="text-right {{ $q < 0 ? 'text-danger' : ($q == 0 ? 'text-muted' : '') }}">{{ $q }}</td>
                            @endforeach
                            @if($matrix['transit'])<td class="text-right text-info">{{ $s->by_branch[$matrix['transit']] ?? 0 }}</td>@endif
                            <td class="text-right font-weight-bold">{{ $s->shown_total }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $matrix['branches']->count() + 3 }}" class="text-center text-muted">No stock.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
