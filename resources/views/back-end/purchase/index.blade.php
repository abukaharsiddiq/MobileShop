@extends('back-end.master')

@section('admin-title', $name)
@section('name', $name)
@section('title', $title)

@section('admin-content')
@php
    $user = auth()->user();
    $canPay = $user->canAny(['create supplier payment', 'update purchase']);
    $canApprove = \App\Services\PurchaseService::canApprove($user);
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Search</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('purchase.index') }}">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Invoice, supplier bill no, supplier name/phone">
                        </div>
                        <div class="col-md-2 mb-2">
                            <input type="text" name="imei" class="form-control" value="{{ request('imei') }}" placeholder="IMEI">
                        </div>
                        <div class="col-md-2 mb-2">
                            <select name="supplier_id" class="form-control select2">
                                <option value="">All suppliers</option>
                                @foreach($suppliers as $s)
                                    <option value="{{ $s->id }}" @selected(request('supplier_id') == $s->id)>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <select name="status" class="form-control">
                                <option value="">All statuses</option>
                                @foreach(\App\Models\Purchase::STATUSES as $key => $label)
                                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <select name="payment_status" class="form-control">
                                <option value="">Any payment</option>
                                @foreach(['Due', 'Partial', 'Paid', 'Returned'] as $status)
                                    <option value="{{ $status }}" @selected(request('payment_status') === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="From"></div>
                        <div class="col-md-2 mb-2"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="To"></div>
                        <div class="col-md-3 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
                            <a href="{{ route('purchase.index') }}" class="btn btn-danger"><i class="fa fa-sync"></i> Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">
                    {{ $name }}
                    @if($pendingCount)
                        <a href="{{ route('purchase.index', ['status' => 'pending']) }}" class="badge badge-warning ml-2">{{ $pendingCount }} waiting for approval</a>
                    @endif
                </h3>
                @can('create purchase')
                <a href="{{ route('purchase.create') }}" class="btn back-btn">
                    <i class="fa fa-plus-circle"></i> Add New Purchase
                </a>
                @endcan
            </div>

            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr class="text-center">
                                <th>#</th>
                                <th>Date</th>
                                <th>Invoice No</th>
                                <th>Supplier</th>
                                <th>Grand Total</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($purchases as $key => $item)
                            <tr>
                                <td class="text-center">{{ $purchases->firstItem() + $key }}</td>
                                <td>{{ date('d-M-Y', strtotime($item->purchase_date)) }}</td>
                                <td>
                                    <a href="{{ route('purchase.show', $item->id) }}"><strong>#{{ $item->invoice_no }}</strong></a>
                                    @if($item->supplier_invoice_no)<br><small class="text-muted">Bill: {{ $item->supplier_invoice_no }}</small>@endif
                                </td>
                                <td>
                                    {{ $item->supplier->name ?? 'N/A' }} <br>
                                    <small class="text-muted">{{ $item->supplier->phone ?? '' }}</small>
                                </td>
                                <td class="text-right">{{ number_format($item->grand_total, 2) }}</td>
                                <td class="text-right text-success">{{ $item->isApproved() ? number_format($item->paid_amount, 2) : '-' }}</td>
                                <td class="text-right text-danger">{{ $item->isApproved() ? number_format($item->due_amount, 2) : '-' }}</td>
                                <td class="text-center"><span class="badge {{ $item->statusBadge() }}">{{ $item->statusLabel() }}</span></td>
                                <td class="text-center">
                                    @if(!$item->isApproved())
                                        <span class="text-muted">-</span>
                                    @elseif($item->payment_status == 'Paid')
                                        <span class="badge badge-success">Paid</span>
                                    @elseif($item->payment_status == 'Partial')
                                        <span class="badge badge-warning">Partial</span>
                                    @elseif($item->payment_status == 'Returned')
                                        <span class="badge badge-secondary">Returned</span>
                                    @else
                                        <span class="badge badge-danger">Due</span>
                                    @endif
                                </td>

                                <td>

                                    <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>

                                      <div class="dropdown-menu" role="menu">

                                         <a class="dropdown-item" href="{{ route('purchase.show', $item->id) }}">
                                          <i class="fa fa-eye"></i> Show
                                        </a>

                                        <a class="dropdown-item" href="{{ route('purchase.print', $item->id) }}" target="_blank">
                                          <i class="fa fa-print"></i> Print Invoice
                                        </a>

                                        @if($item->isOpen())
                                            @can('update purchase')
                                            <a class="dropdown-item" href="{{ route('purchase.edit', $item->id) }}"><i class="fa fa-edit"></i> Edit</a>
                                            @endcan
                                            @if($canApprove)
                                            <form action="{{ route('purchase.status.change', $item->id) }}" method="POST" onsubmit="return confirm('Approve {{ $item->invoice_no }}? Stock, IMEI and the supplier ledger will be updated.')">
                                                @csrf
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="dropdown-item text-success"><i class="fa fa-check"></i> Approve</button>
                                            </form>
                                            @endif
                                        @endif

                                        @if($item->isApproved() && $item->due_amount > 0 && $canPay)
                                            <a class="dropdown-item text-primary" href="javascript:void(0)"
                                               onclick="openPaymentModal({{ $item->id }}, '{{ $item->invoice_no }}', {{ $item->due_amount }})">
                                                <i class="fa fa-money-bill-wave"></i> Pay Now
                                            </a>
                                        @endif

                                       @if(!$item->isCancelled() || !$item->approved_at)
                                       @can('delete purchase')
                                       <form id="deleteForm-{{ $item->id }}" action="{{ route('purchase.delete', $item->id) }}" method="POST" style="display:inline;">
                                          @csrf
                                          @method('DELETE')
                                          <input type="hidden" name="reason">
                                          <a href="javascript:void(0)" onclick="confirmPurchaseDelete({{ $item->id }}, {{ $item->isApproved() ? 'true' : 'false' }})" class="dropdown-item text-danger">
                                              <i class="fa {{ $item->isApproved() ? 'fa-ban' : 'fa-trash' }}"></i> {{ $item->isApproved() ? 'Cancel' : 'Delete' }}
                                          </a>
                                      </form>
                                      @endcan
                                      @endif

                                      </div>
                                 </td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="text-center text-muted">No purchases found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $purchases->links('pagination::bootstrap-4') }}
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Make Payment - Invoice <span id="modal_invoice"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="paymentForm" action="{{ route('purchase.payment') }}" method="POST">
                @csrf
                <input type="hidden" name="purchase_id" id="modal_purchase_id">
                {{-- ekoi form duibar submit hole server ekbar e hishab kore --}}
                <input type="hidden" name="payment_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Current Due</label>
                        <input type="text" id="modal_due_amount" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="form-group">
                        <label>Amount to Pay</label>
                        <input type="number" name="paid_amount" id="modal_pay_amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control" required>
                            @foreach(\App\Http\Controllers\SupplierPaymentController::METHODS as $method)
                                <option value="{{ $method }}">{{ $method === 'Bank' ? 'Bank Transfer' : $method }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reference (Cheque / Trx ID)</label>
                        <input type="text" name="reference_no" class="form-control" maxlength="100" placeholder="Optional - the same reference cannot be used twice">
                    </div>
                    <div class="form-group">
                        <label>Note</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Payment note (optional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('admin-scripts')
<script>
  function openPaymentModal(id, invoice, due) {
    $('#modal_purchase_id').val(id);
    $('#modal_invoice').text(invoice);
    $('#modal_due_amount').val(due);
    $('#modal_pay_amount').attr('max', due).val(due); // ডিফল্টভাবে পুরো ডিউ অ্যামাউন্ট বসবে
    $('#paymentModal').modal('show');
}

// duibar click e duita payment na
$('#paymentForm').on('submit', function () {
    $(this).find('button[type=submit]').prop('disabled', true);
});

// approved: cancel (stock, IMEI, ledger ulto, karon lage); draft/pending: muchhe jay
function confirmPurchaseDelete(id, approved) {
    let form = document.getElementById('deleteForm-' + id);
    if (approved) {
        let reason = prompt('Why are you cancelling this approved purchase? Stock, IMEI and the supplier ledger will be reversed.');
        if (!reason || !reason.trim()) return;
        form.querySelector('input[name=reason]').value = reason.trim();
    } else if (!confirm('Delete this draft/pending purchase?')) {
        return;
    }
    form.submit();
}
</script>
@endpush
