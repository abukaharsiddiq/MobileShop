@extends('back-end.master')
@section('admin-title', $name)
@section('title', $title)
@section('admin-content')
@php
    $user = auth()->user();
    $canPay = $user->can('update sale');
    $canCancel = $user->can('cancel sale');
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ route('sale.index') }}">
                    <div class="row">
                        <div class="col-md-4 mb-2"><input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Invoice, customer name/phone, IMEI"></div>
                        <div class="col-md-2 mb-2"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="From"></div>
                        <div class="col-md-2 mb-2"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="To"></div>
                        <div class="col-md-2 mb-2">
                            <select name="salesperson_id" class="form-control">
                                <option value="">All salespeople</option>
                                @foreach($salespeople as $person)
                                    <option value="{{ $person->id }}" @selected(request('salesperson_id') == $person->id)>{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <select name="branch_id" class="form-control">
                                <option value="">All branches</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <select name="status" class="form-control">
                                <option value="">Any status</option>
                                <option value="completed" @selected(request('status') === 'completed')>Completed</option>
                                <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <select name="payment" class="form-control">
                                <option value="">Any payment</option>
                                <option value="due" @selected(request('payment') === 'due')>With due</option>
                                <option value="paid" @selected(request('payment') === 'paid')>Fully paid</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
                            <a href="{{ route('sale.index') }}" class="btn btn-secondary"><i class="fa fa-sync"></i></a>
                        </div>
                    </div>
                </form>
                <div class="row text-center mt-2">
                    @foreach([['Invoices', $totals->invoices, false], ['Sales', $totals->total, true], ['Paid', $totals->paid, true], ['Due', $totals->due, true]] as [$label, $value, $isMoney])
                    <div class="col"><div class="border rounded p-2"><small class="text-muted text-uppercase">{{ $label }}</small><div class="h6 mb-0">{{ $isMoney ? $money($value) : (int) $value }}</div></div></div>
                    @endforeach
                </div>
                <small class="text-muted">Totals exclude cancelled sales.</small>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">{{ $name }}</h3>
                @can('create sale')
                <a href="{{ route('sale.create') }}" class="btn back-btn">Add New Sale</a>
                @endcan
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>SL</th>
                                <th>Date</th>
                                <th>Invoice No</th>
                                <th>Customer</th>
                                <th>Salesperson / Branch</th>
                                <th>Grand Total</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sales as $key => $sale)
                            @php $status = $sale->paymentStatus(); @endphp
                            <tr class="{{ $sale->isCancelled() ? 'text-muted' : '' }}">
                                <td>{{ $sales->firstItem() + $key }}</td>
                                <td>{{ \Carbon\Carbon::parse($sale->sale_date)->format('d-M-Y') }}</td>
                                <td><a href="{{ route('sale.show', $sale->id) }}" class="badge badge-info">{{ $sale->invoice_no }}</a></td>
                                <td>
                                    {{ $sale->customer->name ?? 'Walking Customer' }} <br>
                                    <small class="text-muted">{{ $sale->customer->phone ?? '' }}</small>
                                </td>
                                <td><small>{{ $sale->salesperson->name ?? '-' }}<br>{{ $sale->branch->name ?? '' }}</small></td>
                                <td>@if($sale->isCancelled())<del>{{ $money($sale->grand_total) }}</del>@else{{ $money($sale->grand_total) }}@endif</td>
                                <td class="text-success">{{ $money($sale->paid_amount) }}</td>
                                <td class="text-danger">{{ $money($sale->due_amount) }}</td>
                                <td>
                                    <span class="badge {{ ['Paid' => 'badge-success', 'Partial' => 'badge-warning', 'Due' => 'badge-danger', 'Cancelled' => 'badge-dark'][$status] }}">{{ $status }}</span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">Actions</button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            @if(!$sale->isCancelled() && $sale->due_amount > 0 && $canPay)
                                            <a class="dropdown-item text-primary" href="javascript:void(0)"
                                               onclick="openPaymentModal({{ $sale->id }}, '{{ $sale->invoice_no }}', {{ $sale->due_amount }})">
                                                <i class="fa fa-money-bill-wave"></i> Pay Now
                                            </a>
                                            @endif
                                            <a class="dropdown-item" href="{{ route('sale.print', $sale->id) }}" target="_blank"><i class="fas fa-print text-primary"></i> Print Receipt</a>
                                            <a class="dropdown-item" href="{{ route('sale.invoice.print.system', $sale->id) }}" target="_blank"><i class="fas fa-print text-primary"></i> Invoice Print</a>
                                            <a class="dropdown-item" href="{{ route('sale.show', $sale->id) }}"><i class="fas fa-eye text-primary"></i> View Detail</a>
                                            @if(!$sale->isCancelled() && $canCancel)
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger" href="javascript:void(0)" onclick="openCancelModal({{ $sale->id }}, '{{ $sale->invoice_no }}', {{ $sale->paid_amount }})">
                                                <i class="fas fa-ban"></i> Cancel Sale
                                            </a>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="text-center text-muted">No sales found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $sales->links('pagination::bootstrap-4') }}
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Make Payment - Invoice <span id="modal_invoice"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="paymentForm" action="{{ route('sale.payment') }}" method="POST">
                @csrf
                <input type="hidden" name="sale_id" id="modal_sale_id">
                <div class="modal-body">
                    <div class="form-group"><label>Current Due</label><input type="text" id="modal_due_amount" class="form-control" readonly></div>
                    <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date" class="form-control" value="{{ date('Y-m-d') }}" required></div>
                    <div class="form-group"><label>Amount to Pay</label><input type="number" name="paid_amount" id="modal_pay_amount" class="form-control" step="0.01" min="0.01" required></div>
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            @foreach($methods as $method)<option value="{{ $method }}">{{ $method }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-group"><label>Reference (Trx / Cheque)</label><input type="text" name="reference" class="form-control" maxlength="100"></div>
                    <div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Cancel Sale <span id="cancel_invoice"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="cancelForm" method="POST">
                @csrf
                <div class="modal-body">
                    <p class="small text-muted">Phones go back to stock, the stock ledger and customer ledger are reversed, and the paid amount (<strong id="cancel_paid"></strong>) is recorded as refunded. The invoice is kept as cancelled.</p>
                    <div class="form-group"><label>Reason *</label><input type="text" name="reason" class="form-control" minlength="5" maxlength="255" required></div>
                    <div class="form-group">
                        <label>Refund method</label>
                        <select name="refund_method" class="form-control">
                            @foreach($methods as $method)<option value="{{ $method }}">{{ $method }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Cancel Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
  function openPaymentModal(id, invoice, due) {
    $('#modal_sale_id').val(id);
    $('#modal_invoice').text(invoice);
    $('#modal_due_amount').val(due);
    $('#modal_pay_amount').attr('max', due).val(due);
    $('#paymentModal').modal('show');
  }

  function openCancelModal(id, invoice, paid) {
    $('#cancelForm').attr('action', `{{ route('sale.cancel', ['id' => ':id']) }}`.replace(':id', id));
    $('#cancel_invoice').text(invoice);
    $('#cancel_paid').text(parseFloat(paid).toFixed(2));
    $('#cancelModal').modal('show');
  }

  // duibar click e duita payment na
  $('#paymentForm, #cancelForm').on('submit', function () {
    $(this).find('button[type=submit]').prop('disabled', true);
  });

  @if(session('auto_print') && session('print_sale_id'))
    window.open("{{ route('sale.print', session('print_sale_id')) }}", '_blank');
  @endif
</script>
@endpush
