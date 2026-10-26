@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php
    $me = auth()->user();
    $own = (int) $session->user_id === (int) $me->id;
    $isToday = $session->business_date->isToday();
    $canReview = $me->can('approve cash closing') && $me->canAccessBranch($session->branch_id) && (!$own || $me->isAdmin());
    $badge = ['open' => 'primary', 'closed' => 'warning', 'recount' => 'danger', 'approved' => 'success'][$session->status] ?? 'secondary';
    $expected = $summary['expected'];
@endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">{{ $session->session_no }} <span class="badge badge-{{ $badge }}">{{ $session->statusLabel() }}</span></h5>
            <div>
                <a href="{{ route('cash-closing.print', $session->id) }}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i> Print</a>
                <a href="{{ route('cash-closing.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Sessions</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-2">
                <div class="col-md-3"><small class="text-muted d-block">Cashier / branch</small>{{ $session->cashier->name }} / {{ $session->branch->name }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Business date</small>{{ $session->business_date->format('d M Y') }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Opened</small>{{ $session->opened_at->format('d-m-Y H:i') }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Closed</small>{{ $session->closed_at?->format('d-m-Y H:i') ?? '-' }}</div>
            </div>
            @if($session->previous_closing !== null && round($session->previous_closing - $session->opening_balance, 2) != 0)
                <div class="alert alert-warning py-1">Opening cash {{ number_format($session->opening_balance, 2) }} differs from the last counted closing {{ number_format($session->previous_closing, 2) }}.</div>
            @endif
            @if($session->review_note)
                <div class="alert alert-info py-1">Manager note: {{ $session->review_note }}</div>
            @endif

            <div class="row text-center mb-3">
                <div class="col-md-2 col-6"><small class="text-muted d-block">Opening</small><h5>{{ number_format($session->opening_balance, 2) }}</h5></div>
                <div class="col-md-2 col-6"><small class="text-muted d-block">Cash in</small><h5 class="text-success">{{ number_format($summary['cash_in'], 2) }}</h5></div>
                <div class="col-md-2 col-6"><small class="text-muted d-block">Cash out</small><h5 class="text-danger">{{ number_format($summary['cash_out'], 2) }}</h5></div>
                <div class="col-md-2 col-6"><small class="text-muted d-block">Expected</small><h5>{{ number_format($expected, 2) }}</h5></div>
                <div class="col-md-2 col-6"><small class="text-muted d-block">Counted</small><h5>{{ $session->counted_balance !== null ? number_format($session->counted_balance, 2) : '-' }}</h5></div>
                <div class="col-md-2 col-6"><small class="text-muted d-block">Short (-) / Over (+)</small>
                    <h5 class="{{ $session->difference < 0 ? 'text-danger' : 'text-success' }}">{{ $session->difference !== null ? number_format($session->difference, 2) : '-' }}</h5></div>
            </div>

            <div class="row">
                <div class="col-lg-6">
                    <h6>Cash by type</h6>
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light"><tr><th>Type</th><th class="text-right">In</th><th class="text-right">Out</th><th class="text-right">Entries</th></tr></thead>
                        <tbody>
                            @forelse($summary['rows'] as $row)
                                <tr><td>{{ $row['category'] }}</td><td class="text-right">{{ number_format($row['in'], 2) }}</td><td class="text-right">{{ number_format($row['out'], 2) }}</td><td class="text-right">{{ $row['count'] }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted">No cash taken or paid yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="col-lg-6">
                    <h6>Bank & mobile <small class="text-muted">(not in the drawer, not in expected cash)</small></h6>
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light"><tr><th>Account</th><th class="text-right">Received</th><th class="text-right">Paid</th></tr></thead>
                        <tbody>
                            @forelse($summary['non_cash'] as $row)
                                <tr><td>{{ $row->name }}</td><td class="text-right">{{ number_format($row->received, 2) }}</td><td class="text-right">{{ number_format($row->paid, 2) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">None.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- cashier: cash in/out ar gona --}}
            @if($own && $session->isOpen() && $isToday)
                <div class="row">
                    <div class="col-lg-5">
                        <div class="card border">
                            <div class="card-header py-2"><h6 class="m-0">Cash in / out</h6></div>
                            <form method="POST" action="{{ route('cash-closing.movement', $session->id) }}" class="card-body" onsubmit="this.querySelector('button').disabled = true">
                                @csrf
                                <div class="form-row">
                                    <div class="form-group col-5">
                                        <select name="direction" class="form-control form-control-sm"><option value="in">Cash in</option><option value="out">Cash out</option></select>
                                    </div>
                                    <div class="form-group col-7"><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required></div>
                                </div>
                                <div class="form-group">
                                    <select name="account_id" class="form-control form-control-sm" required>
                                        @foreach($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->label() }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">From/to: bank, owner's capital or drawings, an income or expense head.</small>
                                </div>
                                <div class="form-group"><input type="text" name="note" class="form-control form-control-sm" placeholder="Why?" required maxlength="255"></div>
                                <button class="btn btn-sm btn-outline-primary">Record</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

            @if($own && (($session->isOpen() && $isToday) || $session->status === 'recount'))
                <div class="card border mt-2">
                    <div class="card-header py-2"><h6 class="m-0">Count the drawer and close</h6></div>
                    <form method="POST" action="{{ route('cash-closing.close', $session->id) }}" class="card-body" id="countForm"
                          onsubmit="return confirm('Close the session? No more cash can be taken or paid in it.')">
                        @csrf
                        <input type="hidden" name="version" value="{{ $session->version }}">
                        <div class="form-row">
                            @foreach($notes as $note)
                                <div class="form-group col-md-1 col-3">
                                    <label class="small mb-0">{{ $note }} x</label>
                                    <input type="number" min="0" name="denominations[{{ $note }}]" class="form-control form-control-sm js-note" data-note="{{ $note }}">
                                </div>
                            @endforeach
                            <div class="form-group col-md-2 col-6">
                                <label class="small mb-0">Coins / loose</label>
                                <input type="number" step="0.01" min="0" name="coins" class="form-control form-control-sm js-coins">
                            </div>
                        </div>
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-3">
                                <label>Total counted <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" name="counted_balance" id="counted" class="form-control" required>
                                <small class="text-muted">Filled from the notes, or type the total.</small>
                            </div>
                            <div class="form-group col-md-6">
                                <label>Note</label>
                                <input type="text" name="closing_note" class="form-control" maxlength="1000">
                            </div>
                            <div class="form-group col-md-3"><button class="btn btn-success btn-block"><i class="fa fa-lock"></i> Submit count</button></div>
                        </div>
                    </form>
                </div>
            @endif

            {{-- manager review --}}
            @if($canReview && in_array($session->status, ['closed', 'recount'], true))
                <div class="card border mt-2">
                    <div class="card-header py-2"><h6 class="m-0">Manager review</h6></div>
                    <div class="card-body">
                        <form method="POST" class="form-row">
                            @csrf
                            <input type="hidden" name="version" value="{{ $session->version }}">
                            <div class="form-group col-md-6"><input type="text" name="review_note" class="form-control" maxlength="1000"
                                placeholder="{{ $session->difference != 0 ? 'Explain the shortage / overage (required)' : 'Note' }}"></div>
                            <div class="form-group col-md-6">
                                @if($session->status === 'closed')
                                    <button formaction="{{ route('cash-closing.approve', $session->id) }}" class="btn btn-success btn-sm"
                                            onclick="return confirm('Approve? The shortage/overage is posted and the closing is locked.')"><i class="fa fa-check"></i> Approve</button>
                                    <button formaction="{{ route('cash-closing.recount', $session->id) }}" class="btn btn-warning btn-sm"><i class="fa fa-redo"></i> Ask for recount</button>
                                @endif
                                @if($isToday)
                                    <button formaction="{{ route('cash-closing.reopen', $session->id) }}" class="btn btn-outline-primary btn-sm"><i class="fa fa-unlock"></i> Reopen</button>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            {{-- approve er pore shudhu adjustment --}}
            @if($session->status === 'approved')
                <h6 class="mt-3">Adjustments after approval</h6>
                <table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th>Requested</th><th class="text-right">Amount</th><th>Reason</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse($session->adjustments as $adjustment)
                            <tr>
                                <td>{{ $adjustment->requester->name ?? '' }}, {{ $adjustment->created_at->format('d-m-Y') }}</td>
                                <td class="text-right">{{ number_format($adjustment->amount, 2) }}</td>
                                <td>{{ $adjustment->reason }} @if($adjustment->review_note)<br><small class="text-muted">{{ $adjustment->review_note }}</small>@endif</td>
                                <td>{{ ucfirst($adjustment->status) }} {{ $adjustment->reviewer ? 'by ' . $adjustment->reviewer->name : '' }}</td>
                                <td>
                                    @if($adjustment->status === 'pending' && $canReview && ((int) $adjustment->requested_by !== (int) $me->id || $me->isAdmin()))
                                        <form method="POST" action="{{ route('cash-closing.adjustment.review', $adjustment->id) }}" class="form-inline">
                                            @csrf
                                            <input type="text" name="review_note" class="form-control form-control-sm mr-1" placeholder="Note">
                                            <button name="decision" value="approve" class="btn btn-sm btn-success mr-1">Approve</button>
                                            <button name="decision" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">None. Approved closings change only through an approved adjustment.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if($me->hasAnyPermission(['read cash closing', 'approve cash closing']) || $me->isAdmin())
                    <form method="POST" action="{{ route('cash-closing.adjustment.request', $session->id) }}" class="form-inline">
                        @csrf
                        <input type="number" step="0.01" name="amount" class="form-control form-control-sm mr-1" placeholder="+ found / - missing" required>
                        <input type="text" name="reason" class="form-control form-control-sm mr-1" placeholder="Reason" required maxlength="1000" style="min-width:250px">
                        <button class="btn btn-sm btn-outline-primary">Request adjustment</button>
                    </form>
                @endif
            @endif

            <h6 class="mt-3">Cash entries in this session</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th>Time</th><th>Entry</th><th>Type</th><th>Reference</th><th>Details</th><th class="text-right">In</th><th class="text-right">Out</th></tr></thead>
                    <tbody>
                        @forelse($summary['lines'] as $line)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($line->created_at)->format('H:i') }}</td>
                                <td><a href="{{ route('finance.journal.show', $line->entry_id) }}">{{ $line->entry_no }}</a></td>
                                <td>{{ $line->category }}</td>
                                <td>{{ $line->reference_no }}</td>
                                <td><small>{{ $line->narration }}</small></td>
                                <td class="text-right">{{ $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                                <td class="text-right">{{ $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No cash entries.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(function () {
        function total() {
            let sum = parseFloat($('.js-coins').val()) || 0, any = false;
            $('.js-note').each(function () {
                const n = parseInt(this.value) || 0;
                if (n) any = true;
                sum += n * parseInt($(this).data('note'));
            });
            if (any || $('.js-coins').val()) $('#counted').val(sum.toFixed(2));
        }
        $('.js-note, .js-coins').on('input', total);
    });
</script>
@endpush
