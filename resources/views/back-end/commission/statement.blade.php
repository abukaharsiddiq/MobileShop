@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $s = $statement; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">{{ $s->statement_no }} <span class="badge badge-{{ $s->statusBadge() }}">{{ $s->statusLabel() }}</span></h5>
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-secondary d-print-none"><i class="fa fa-print"></i> Print</button>
                <a href="{{ route('commission.statements') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-arrow-left"></i> Statements</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row small mb-3">
                <div class="col-md-3"><strong>Staff:</strong> {{ $s->user->name }} ({{ $s->user->roleLabel() }})</div>
                <div class="col-md-3"><strong>Branch:</strong> {{ $s->user->branch->name ?? 'All branches' }}</div>
                <div class="col-md-3"><strong>Period:</strong> {{ $s->period_start->format('d-m-Y') }} - {{ $s->period_end->format('d-m-Y') }}</div>
                <div class="col-md-3"><strong>Approved:</strong> {{ $s->approver->name ?? '-' }}{{ $s->approved_at ? ', ' . $s->approved_at->format('d-m-Y') : '' }}
                    @if($s->paid_at)<br><strong>Paid:</strong> {{ $s->paid_at->format('d-m-Y') }} ({{ $s->account->name ?? $s->payment_method }}) by {{ $s->payer->name ?? '' }}@endif</div>
                @if($s->note)<div class="col-md-6"><strong>Note:</strong> {{ $s->note }}</div>@endif
                @if($s->cancel_reason)<div class="col-md-6 text-danger"><strong>Cancelled:</strong> {{ $s->cancel_reason }}</div>@endif
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="thead-light"><tr><th>Date</th><th>Type</th><th>Detail</th><th>Rule</th><th class="text-right">Qty</th><th class="text-right">Base</th><th class="text-right">Commission</th></tr></thead>
                    <tbody>
                        @forelse($s->entries as $e)
                            <tr class="{{ $e->amount < 0 ? 'text-danger' : '' }}">
                                <td>{{ $e->entry_date->format('d-m-Y') }}</td>
                                <td>{{ $e->label() }}</td>
                                <td>{{ $e->description }}</td>
                                <td>{{ $e->rule->name ?? ($e->type === 'bonus' ? 'Target' : '-') }}</td>
                                <td class="text-right">{{ $e->qty ?: '' }}</td>
                                <td class="text-right">{{ number_format($e->base_amount, 2) }}</td>
                                <td class="text-right">{{ number_format($e->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No commission in this period (no matching rule or sale).</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr><th colspan="6" class="text-right">Earned</th><th class="text-right">{{ number_format($s->earned, 2) }}</th></tr>
                        <tr><th colspan="6" class="text-right">Returns / cancelled sales</th><th class="text-right text-danger">{{ number_format($s->reversed, 2) }}</th></tr>
                        <tr><th colspan="6" class="text-right">Target bonus</th><th class="text-right">{{ number_format($s->bonus, 2) }}</th></tr>
                        <tr><th colspan="6" class="text-right">Net payable</th><th class="text-right">{{ number_format($s->net, 2) }}</th></tr>
                    </tfoot>
                </table>
            </div>

            <div class="d-print-none">
                @if($s->status === 'draft' && $canManage)
                    <form method="POST" action="{{ route('commission.statements.refresh', $s->id) }}" class="d-inline">@csrf
                        <button class="btn btn-sm btn-outline-primary"><i class="fa fa-sync"></i> Recalculate</button></form>
                    <form method="POST" action="{{ route('commission.statements.cancel', $s->id) }}" class="d-inline-flex ml-2">@csrf
                        <input type="text" name="reason" class="form-control form-control-sm mr-1" placeholder="Reason" required>
                        <button class="btn btn-sm btn-outline-danger">Cancel draft</button></form>
                @endif
                @if($s->status === 'draft' && $canApprove)
                    <form method="POST" action="{{ route('commission.statements.approve', $s->id) }}" class="d-inline ml-2" onsubmit="return confirm('Approve {{ number_format($s->net, 2) }}? It is posted as payable and cannot be changed.')">@csrf
                        <button class="btn btn-sm btn-success">Approve</button></form>
                @endif
                @if($s->status === 'approved' && $canApprove)
                    <form method="POST" action="{{ route('commission.statements.pay', $s->id) }}" class="form-inline mt-2">@csrf
                        <select name="payment_method" class="form-control form-control-sm mr-1">@foreach($methods as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach</select>
                        <select name="account_id" class="form-control form-control-sm mr-1"><option value="">Default account</option>
                            @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                        <button class="btn btn-sm btn-primary">Pay {{ number_format($s->net, 2) }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
