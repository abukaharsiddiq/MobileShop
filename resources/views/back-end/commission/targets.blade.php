@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Sales Targets - {{ $month->format('F Y') }}</h5>
            <form method="GET" class="form-inline">
                <input type="month" name="month" class="form-control form-control-sm mr-2" value="{{ $month->format('Y-m') }}">
                <button class="btn btn-sm btn-primary">Show</button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="thead-light"><tr><th>Staff</th><th class="text-right">Net sales so far</th><th class="text-right">Achieved</th><th style="width:55%">Target & bonus</th></tr></thead>
                <tbody>
                    @forelse($people as $p)
                        @php $t = $targets[$p->id] ?? null; $a = $achieved[$p->id] ?? 0; @endphp
                        <tr>
                            <td>{{ $p->name }}<br><small class="text-muted">{{ $p->roleLabel() }}, {{ $p->branch->name ?? 'all branches' }}</small></td>
                            <td class="text-right">{{ number_format($a, 2) }}</td>
                            <td class="text-right {{ $t && $a >= $t->target_amount ? 'text-success font-weight-bold' : '' }}">{{ $t ? round($a / max(1, (float) $t->target_amount) * 100, 1) . '%' : '-' }}</td>
                            <td>
                                <form method="POST" action="{{ route('commission.targets.store') }}" class="form-inline">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $p->id }}">
                                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                                    <input type="number" step="0.01" min="1" name="target_amount" class="form-control form-control-sm mr-1 mb-1" style="width:120px" placeholder="Target" value="{{ $t?->target_amount }}" required>
                                    <select name="bonus_type" class="form-control form-control-sm mr-1 mb-1">
                                        <option value="fixed" @selected($t?->bonus_type === 'fixed')>Bonus Tk</option>
                                        <option value="percent" @selected($t?->bonus_type === 'percent')>Bonus % of sales</option>
                                    </select>
                                    <input type="number" step="0.01" min="0" name="bonus_value" class="form-control form-control-sm mr-1 mb-1" style="width:90px" value="{{ $t?->bonus_value ?? 0 }}" required>
                                    <button class="btn btn-sm btn-outline-primary mb-1">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No staff.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="small text-muted mb-0">The bonus is added to the commission statement that covers the end of the month, if net sales (after returns) reach the target.</p>
        </div>
    </div>
</div>
@endsection
