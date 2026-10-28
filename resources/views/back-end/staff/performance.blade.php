@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Staff Performance</h5>
            <form method="GET" class="form-inline">
                <select name="branch_id" class="form-control form-control-sm mr-2">
                    <option value="">All my branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach
                </select>
                <input type="date" name="from_date" class="form-control form-control-sm mr-2" value="{{ $from }}">
                <input type="date" name="to_date" class="form-control form-control-sm mr-2" value="{{ $to }}">
                <button class="btn btn-sm btn-primary">Show</button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-bordered table-hover">
                <thead class="thead-light"><tr><th>Staff</th><th class="text-right">Invoices</th><th class="text-right">Units</th><th class="text-right">Net sales</th>
                    <th class="text-right">Gross profit</th><th class="text-right">Returns</th><th class="text-right">Target</th>
                    @if($showCommission)<th class="text-right">Commission</th>@endif<th class="text-right">Days present</th></tr></thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td><a href="{{ route('staff.show', $r['user']->id) }}">{{ $r['user']->name }}</a><br><small class="text-muted">{{ $r['user']->roleLabel() }}, {{ $r['user']->branch->name ?? 'all branches' }}</small></td>
                            <td class="text-right">{{ $r['invoices'] }}</td>
                            <td class="text-right">{{ $r['units'] }}</td>
                            <td class="text-right">{{ number_format($r['net_sales'], 2) }}</td>
                            <td class="text-right">{{ number_format($r['profit'], 2) }}</td>
                            <td class="text-right">{{ $r['returns'] }}{{ $r['returned'] ? ' (' . number_format($r['returned'], 2) . ')' : '' }}</td>
                            <td class="text-right">{{ $r['target'] !== null ? number_format($r['target'], 2) . ' (' . $r['achievement'] . '%)' : '-' }}</td>
                            @if($showCommission)<td class="text-right">{{ number_format($r['commission'], 2) }}</td>@endif
                            <td class="text-right">{{ $r['present'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted">No staff.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="small text-muted mb-0">Net sales and profit are completed invoices after returns (line totals, before invoice discount). Targets show for a full calendar month.
                Commission is what has been calculated into statements for the period.</p>
        </div>
    </div>
</div>
@endsection
