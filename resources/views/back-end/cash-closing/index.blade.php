@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $badge = ['open' => 'primary', 'closed' => 'warning', 'recount' => 'danger', 'approved' => 'success']; @endphp
<div class="container-fluid">
    <div class="row">
        {{-- nijer drawer --}}
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="m-0">My cash drawer</h6></div>
                <div class="card-body">
                    @if($mine)
                        <p class="mb-2">Session <strong>{{ $mine->session_no }}</strong> ({{ $mine->business_date->format('d-m-Y') }})
                            <span class="badge badge-{{ $badge[$mine->status] }}">{{ $mine->statusLabel() }}</span></p>
                        <a href="{{ route('cash-closing.show', $mine->id) }}" class="btn btn-primary btn-sm">
                            {{ $mine->isOpen() ? 'Open drawer / close day' : 'Recount' }}
                        </a>
                    @elseif($todayDone)
                        <p class="mb-2">Today's session <strong>{{ $todayDone->session_no }}</strong> is <span class="badge badge-{{ $badge[$todayDone->status] }}">{{ $todayDone->statusLabel() }}</span>.</p>
                        <a href="{{ route('cash-closing.show', $todayDone->id) }}" class="btn btn-outline-primary btn-sm">View</a>
                    @else
                        @can('open cash session')
                            <form method="POST" action="{{ route('cash-closing.open') }}" onsubmit="this.querySelector('button').disabled = true">
                                @csrf
                                <div class="form-group">
                                    <label>Opening cash in drawer <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" name="opening_balance" class="form-control" value="{{ old('opening_balance') }}" required>
                                </div>
                                @if($branches->count() > 1)
                                    <div class="form-group">
                                        <label>Branch</label>
                                        <select name="branch_id" class="form-control">
                                            @foreach($branches as $branch)
                                                <option value="{{ $branch->id }}" @selected((int) old('branch_id', auth()->user()->branch_id) === $branch->id)>{{ $branch->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                                <div class="form-group">
                                    <label>Note</label>
                                    <input type="text" name="opening_note" class="form-control" maxlength="1000" value="{{ old('opening_note') }}">
                                </div>
                                <button class="btn btn-success"><i class="fa fa-cash-register"></i> Open today's session</button>
                            </form>
                        @else
                            <p class="text-muted mb-0">You review cash closings; you do not run a drawer.</p>
                        @endcan
                    @endif
                </div>
            </div>

            @if($pending->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Adjustments awaiting approval</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach($pending as $adjustment)
                            <li class="list-group-item">
                                <a href="{{ route('cash-closing.show', $adjustment->cash_session_id) }}">{{ $adjustment->session->session_no }}</a>:
                                {{ number_format($adjustment->amount, 2) }} - <small>{{ $adjustment->reason }}</small>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="m-0">{{ $all ? 'Cash sessions' : 'My cash sessions' }}</h6>
                    <a href="{{ route('cash-closing.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="fa fa-file-csv"></i> Export CSV</a>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-row mb-2">
                        <div class="col-md-3 col-6 mb-2"><input type="date" name="from_date" class="form-control form-control-sm" value="{{ request('from_date') }}"></div>
                        <div class="col-md-3 col-6 mb-2"><input type="date" name="to_date" class="form-control form-control-sm" value="{{ request('to_date') }}"></div>
                        <div class="col-md-2 col-6 mb-2">
                            <select name="status" class="form-control form-control-sm">
                                <option value="">Any status</option>
                                @foreach(\App\Models\CashSession::statuses() as $key => $label)
                                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($all)
                            <div class="col-md-2 col-6 mb-2">
                                <select name="user_id" class="form-control form-control-sm">
                                    <option value="">All cashiers</option>
                                    @foreach($cashiers as $cashier)
                                        <option value="{{ $cashier->id }}" @selected((int) request('user_id') === $cashier->id)>{{ $cashier->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="col-md-2 col-12 mb-2"><button class="btn btn-sm btn-primary btn-block">Filter</button></div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead class="thead-light">
                                <tr><th>Session</th><th>Date</th><th>Branch</th><th>Cashier</th><th class="text-right">Opening</th><th class="text-right">Expected</th><th class="text-right">Counted</th><th class="text-right">Short / Over</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                @forelse($sessions as $s)
                                    <tr>
                                        <td><a href="{{ route('cash-closing.show', $s->id) }}">{{ $s->session_no }}</a></td>
                                        <td>{{ $s->business_date->format('d-m-Y') }}</td>
                                        <td>{{ $s->branch->name ?? '' }}</td>
                                        <td>{{ $s->cashier->name ?? '' }}</td>
                                        <td class="text-right">{{ number_format($s->opening_balance, 2) }}</td>
                                        <td class="text-right">{{ $s->expected_balance !== null ? number_format($s->expected_balance, 2) : '-' }}</td>
                                        <td class="text-right">{{ $s->counted_balance !== null ? number_format($s->counted_balance, 2) : '-' }}</td>
                                        <td class="text-right {{ $s->difference < 0 ? 'text-danger' : ($s->difference > 0 ? 'text-success' : '') }}">{{ $s->difference !== null ? number_format($s->difference, 2) : '-' }}</td>
                                        <td><span class="badge badge-{{ $badge[$s->status] ?? 'secondary' }}">{{ $s->statusLabel() }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center text-muted">No cash session found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $sessions->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
