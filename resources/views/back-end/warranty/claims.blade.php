@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Warranty Claims</h5>
            <a href="{{ route('warranty.index') }}" class="btn btn-sm back-btn"><i class="fa fa-search"></i> Lookup</a>
        </div>
        <div class="card-body">
            <form method="GET" class="form-inline mb-3">
                <select name="status" class="form-control form-control-sm mr-2">
                    <option value="">All</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    @foreach(\App\Models\WarrantyClaim::LABELS as $key => $label)
                        <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <input type="text" name="q" class="form-control form-control-sm mr-2" value="{{ request('q') }}" placeholder="Claim no / IMEI / warranty no">
                <button class="btn btn-sm btn-primary">Filter</button>
            </form>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light"><tr><th>Claim</th><th>Reported</th><th>Customer</th><th>Product / IMEI</th><th>Problem</th><th>Status</th><th>Result</th></tr></thead>
                    <tbody>
                        @forelse($claims as $claim)
                            <tr>
                                <td><a href="{{ route('warranty.claims.show', $claim->id) }}">{{ $claim->claim_no }}</a></td>
                                <td>{{ $claim->reported_on->format('d-m-Y') }}</td>
                                <td>{{ $claim->customer->name ?? '' }}<br><small>{{ $claim->customer->phone ?? '' }}</small></td>
                                <td>{{ $claim->warranty->product->product_name ?? '' }}<br><small>{{ $claim->warranty->imei_no }}</small></td>
                                <td>{{ $claim->reasonLabel() }}</td>
                                <td><span class="badge badge-{{ $claim->isOpen() ? 'info' : 'secondary' }}">{{ $claim->statusLabel() }}</span></td>
                                <td>{{ \App\Models\WarrantyClaim::RESOLUTIONS[$claim->resolution] ?? ucfirst((string) $claim->resolution) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No claims.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $claims->links() }}
        </div>
    </div>
</div>
@endsection
