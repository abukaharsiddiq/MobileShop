@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    {{-- cash / bank / mobile banking er bortoman balance --}}
    <div class="row">
        @foreach($money as $account)
            <div class="col-xl-3 col-md-4 col-6 mb-3">
                <div class="card h-100 border-left-{{ $account->subtype === 'cash' ? 'success' : ($account->subtype === 'bank' ? 'primary' : 'info') }}">
                    <div class="card-body py-2">
                        <div class="small text-muted text-uppercase">{{ \App\Models\Account::moneySubtypes()[$account->subtype] }}{{ $account->status ? '' : ' (inactive)' }}</div>
                        <div class="font-weight-bold">{{ $account->name }}</div>
                        <div class="h5 mb-0 {{ $account->current_balance < 0 ? 'text-danger' : '' }}">{{ number_format($account->current_balance, 2) }}</div>
                        <a href="{{ route('finance.accounts.statement', $account->id) }}" class="small">Cash / bank book &rarr;</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Chart of Accounts</h5>
            <div>
                @can('manage accounts')
                    <a href="{{ route('finance.years.index') }}" class="btn btn-sm btn-outline-secondary"><i class="fa fa-calendar"></i> Financial Years</a>
                    <a href="{{ route('finance.accounts.create') }}" class="btn btn-sm back-btn"><i class="fa fa-plus"></i> New Account</a>
                @endcan
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light">
                        <tr><th>Code</th><th>Account</th><th>Type</th><th>Kind</th><th>Payment method</th><th class="text-right">Balance</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($accounts->groupBy('type') as $type => $rows)
                            <tr class="table-secondary"><td colspan="8" class="font-weight-bold">{{ \App\Models\Account::types()[$type] ?? $type }}</td></tr>
                            @foreach($rows as $account)
                                <tr>
                                    <td>{{ $account->code }}</td>
                                    <td>
                                        {{ $account->name }}
                                        @if($account->is_system)<span class="badge badge-light border">system</span>@endif
                                        @if($account->account_number)<br><small class="text-muted">{{ $account->bank_name }} {{ $account->account_number }}</small>@endif
                                    </td>
                                    <td>{{ ucfirst($account->type) }}</td>
                                    <td>{{ ucwords(str_replace('_', ' ', $account->subtype)) }}</td>
                                    <td>{{ $account->payment_method }}</td>
                                    <td class="text-right {{ $account->current_balance < 0 ? 'text-danger' : '' }}">{{ number_format($account->current_balance, 2) }}</td>
                                    <td>{!! $account->status ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' !!}</td>
                                    <td class="text-nowrap">
                                        <a href="{{ route('finance.accounts.statement', $account->id) }}" class="btn btn-sm btn-outline-primary" title="Statement"><i class="fa fa-list"></i></a>
                                        @can('manage accounts')
                                            <a href="{{ route('finance.accounts.edit', $account->id) }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fa fa-edit"></i></a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <small class="text-muted">
                Balances come from posted journal entries. Sales, purchases, payments, returns and expenses post automatically;
                the payment method of a cash/bank account decides where POS and supplier payments of that method go.
            </small>
        </div>
    </div>
</div>
@endsection
