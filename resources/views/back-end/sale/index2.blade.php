@extends('back-end.master')

@section('admin-title', $name)
@section('title', $title)

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $name }}</h3>
                <a href="{{ route('sale.create') }}" class="btn back-btn">Add New Sale</a>
            </div>
            
            <div class="card-body">
                <div class="table-responsive">
                    <table id="example1" class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>SL</th>
                                <th>Date</th>
                                <th>Invoice No</th>
                                <th>Customer</th>
                                <th>Grand Total</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sales as $key => $sale)
                            <tr>
                                <td>{{ $key + 1 }}</td>
                                <td>{{ \Carbon\Carbon::parse($sale->sale_date)->format('d-M-Y') }}</td>
                                <td><span class="badge badge-info">{{ $sale->invoice_no }}</span></td>
                                <td>
                                    {{ $sale->customer->name ?? 'Walking Customer' }} <br>
                                    <small class="text-muted">{{ $sale->customer->phone ?? '' }}</small>
                                </td>
                                <td>{{ number_format($sale->grand_total, 2) }}</td>
                                <td class="text-success">{{ number_format($sale->paid_amount, 2) }}</td>
                                <td class="text-danger">{{ number_format($sale->due_amount, 2) }}</td>
                                <td>
                                    @if($sale->due_amount <= 0)
                                        <span class="badge badge-success">Paid</span>
                                    @elseif($sale->paid_amount > 0)
                                        <span class="badge badge-warning">Partial</span>
                                    @else
                                        <span class="badge badge-danger">Due</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">
                                            Actions
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            @if($sale->due_amount > 0)
                                            <a class="dropdown-item text-primary" href="javascript:void(0)" 
                                               onclick="openPaymentModal({{ $sale->id }}, '{{ $sale->invoice_no }}', {{ $sale->due_amount }}, {{ $sale->customer_id }})">
                                                <i class="fa fa-money-bill-wave"></i> Pay Now
                                            </a>
                                            @endif
                                            <a class="dropdown-item" href="{{ route('sale.show', $sale->id) }}">
                                                <i class="fas fa-eye text-primary"></i> View Detail
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <form action="{{ route('sale.delete', $sale->id) }}" method="POST" onsubmit="return confirm('Are you sure?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Make Payment - Invoice <span id="modal_invoice"></span></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="paymentForm" action="{{ route('sale.payment') }}" method="POST">
                @csrf
                <input type="hidden" name="sale_id" id="modal_sale_id">
                
                <div class="modal-body">
                    <div class="alert alert-info py-2">
                        <strong>Customer: </strong> <span id="modal_cust_name"></span>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Invoice Due</label>
                        <input type="text" id="modal_due_amount" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>

                    <div class="form-group">
                        <label>Amount to Pay (৳)</label>
                        <input type="number" name="paid_amount" id="modal_pay_amount" class="form-control border-success" step="0.01" required>
                        <small class="text-danger" id="pay_err"></small>
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="Bkash">Bkash</option>
                            <option value="Bank">Bank</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Note</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Cheque no or reference..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success" id="subBtn">Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('admin-scripts')
<script>
  function openPaymentModal(id, invoice, due, customerName) {
    $('#modal_sale_id').val(id);
    $('#modal_invoice').text(invoice);
    $('#modal_cust_name').text(customerName);
    $('#modal_due_amount').val(due);
    $('#modal_pay_amount').attr('max', due).val(due); // ডিফল্টভাবে ফুল ডিউ বসবে
    $('#paymentModal').modal('show');
}

// ইনপুট ভ্যালিডেশন
$('#modal_pay_amount').on('input', function() {
    let due = parseFloat($('#modal_due_amount').val());
    let pay = parseFloat($(this).val());
    if (pay > due) {
        $('#pay_err').text('Amount exceeds invoice due!');
        $('#subBtn').attr('disabled', true);
    } else {
        $('#pay_err').text('');
        $('#subBtn').attr('disabled', false);
    }
});
</script>
@endpush