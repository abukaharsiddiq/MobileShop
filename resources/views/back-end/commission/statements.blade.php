@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    @if($canManage)
        <div class="card">
            <div class="card-header"><h6 class="m-0">Create statement</h6></div>
            <form method="POST" action="{{ route('commission.statements.generate') }}" class="card-body form-row">
                @csrf
                <div class="col-md-4 mb-2">
                    <select name="user_id" class="form-control form-control-sm select2bs4" required>
                        <option value="">-- staff --</option>
                        @foreach($people as $p)<option value="{{ $p->id }}" @selected(old('user_id') == $p->id)>{{ $p->name }} ({{ $p->roleLabel() }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2"><input type="date" name="from_date" class="form-control form-control-sm" value="{{ old('from_date', now()->subMonthNoOverflow()->startOfMonth()->toDateString()) }}" required></div>
                <div class="col-md-2 col-6 mb-2"><input type="date" name="to_date" class="form-control form-control-sm" value="{{ old('to_date', now()->subMonthNoOverflow()->endOfMonth()->toDateString()) }}" required></div>
                <div class="col-md-2 mb-2"><input type="text" name="note" class="form-control form-control-sm" placeholder="Note"></div>
                <div class="col-md-2 mb-2"><button class="btn btn-sm btn-primary">Calculate</button></div>
            </form>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Commission Statements</h5>
            <form method="GET" class="form-inline">
                @if($people->isNotEmpty())
                    <select name="user_id" class="form-control form-control-sm mr-2"><option value="">All staff</option>
                        @foreach($people as $p)<option value="{{ $p->id }}" @selected(request('user_id') == $p->id)>{{ $p->name }}</option>@endforeach</select>
                @endif
                <select name="status" class="form-control form-control-sm mr-2"><option value="">Any status</option>
                    @foreach(\App\Models\CommissionStatement::LABELS as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
                <button class="btn btn-sm btn-primary">Filter</button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-bordered table-hover">
                <thead class="thead-light"><tr><th>Statement</th><th>Staff</th><th>Period</th><th class="text-right">Earned</th><th class="text-right">Returns / cancels</th>
                    <th class="text-right">Bonus</th><th class="text-right">Net</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($statements as $s)
                        <tr>
                            <td><a href="{{ route('commission.statements.show', $s->id) }}">{{ $s->statement_no }}</a></td>
                            <td>{{ $s->user->name ?? '' }}</td>
                            <td>{{ $s->period_start->format('d-m-Y') }} - {{ $s->period_end->format('d-m-Y') }}</td>
                            <td class="text-right">{{ number_format($s->earned, 2) }}</td>
                            <td class="text-right text-danger">{{ number_format($s->reversed, 2) }}</td>
                            <td class="text-right">{{ number_format($s->bonus, 2) }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($s->net, 2) }}</td>
                            <td><span class="badge badge-{{ $s->statusBadge() }}">{{ $s->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No statements.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $statements->links() }}
        </div>
    </div>
</div>
@endsection
