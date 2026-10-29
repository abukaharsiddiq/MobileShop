@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">IMEI Reconciliation</h5>
            <a href="{{ route('reports.hub') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-th-large"></i> All reports</a>
        </div>
        <div class="card-body">
            @component('back-end.reports._filters', ['branches' => $branches, 'branchId' => $branchId, 'noDates' => true, 'from' => null, 'to' => null])
                <div class="col-md-3 col-6 mb-2"><label class="small mb-1">Product / model</label>
                    <input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}"></div>
                <div class="col-md-2 col-6 mb-2"><label class="small mb-1 d-block">&nbsp;</label>
                    <label class="small"><input type="checkbox" name="mismatch" value="1" @checked(request('mismatch'))> Only mismatches</label></div>
            @endcomponent

            <div class="d-flex flex-wrap mb-3" style="gap:8px">
                @foreach($status as $s)
                    <span class="badge badge-light border p-2">{{ $s['label'] }}: {{ number_format($s['count']) }}</span>
                @endforeach
            </div>
            <div class="alert {{ $mismatches ? 'alert-warning' : 'alert-success' }} py-2">
                <i class="fas fa-{{ $mismatches ? 'exclamation-triangle' : 'check-circle' }}"></i>
                {{ $mismatches ? $mismatches . ' variant/branch row(s) where the stock ledger and the phones on hand differ.' : 'Stock ledger and phones on hand match everywhere.' }}
                @if($mismatches && Route::has('stock-reconciliation.index'))<a href="{{ route('stock-reconciliation.index') }}">Fix in stock reconciliation</a>@endif
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light"><tr><th>Product / variant</th><th>Branch</th><th class="text-right">Ledger qty</th><th class="text-right">Available</th><th class="text-right">Reserved</th>
                        <th class="text-right">In repair</th><th class="text-right">Damaged</th><th class="text-right">In transit</th><th class="text-right">Phones on hand</th><th class="text-right">Difference</th></tr></thead>
                    <tbody>
                        @forelse($rows as $r)
                            <tr class="{{ $r['difference'] ? 'table-warning' : '' }}">
                                <td>{{ $r['stock']->variantLabel() }}</td>
                                <td>{{ $r['branch'] }}</td>
                                <td class="text-right">{{ $r['ledger'] }}</td>
                                <td class="text-right">{{ $r['available'] }}</td>
                                <td class="text-right">{{ $r['reserved'] }}</td>
                                <td class="text-right">{{ $r['repair'] }}</td>
                                <td class="text-right">{{ $r['damaged'] }}</td>
                                <td class="text-right">{{ $r['transit'] }}</td>
                                <td class="text-right font-weight-bold">{{ $r['owned'] }}</td>
                                <td class="text-right font-weight-bold">{{ $r['difference'] > 0 ? '+' : '' }}{{ $r['difference'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted">No phone stock.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $rows->links() }}
            <p class="small text-muted mb-0">Difference = ledger quantity - phones on hand. Positive: stock without a registered IMEI. Negative: phones registered but not in the ledger.</p>
        </div>
    </div>
</div>
@endsection
