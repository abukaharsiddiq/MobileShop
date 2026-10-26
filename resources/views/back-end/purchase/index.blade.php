@extends('back-end.master')

@section('admin-title', $name)
@section('name', $name)
@section('title', $title)

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">{{ $name }}</h3>
                <a href="{{ route('purchase.create') }}" class="btn back-btn">
                    <i class="fa fa-plus-circle"></i> Add New Purchase
                </a>
            </div>
            
            <div class="card-body">
                <div class="table-responsive">
                    <table id="example1" class="table table-bordered table-striped">
                        <thead>
                            <tr class="text-center">
                                <th>SL</th>
                                <th>Date</th>
                                <th>Invoice No</th>
                                <th>Supplier</th>
                                <th>Grand Total</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($purchases as $key => $item)
                            <tr>
                                <td class="text-center">{{ $key + 1 }}</td>
                                <td>{{ date('d-M-Y', strtotime($item->purchase_date)) }}</td>
                                <td><strong>#{{ $item->invoice_no }}</strong></td>
                                <td>
                                    {{ $item->supplier->name ?? 'N/A' }} <br>
                                    <small class="text-muted">{{ $item->supplier->phone ?? '' }}</small>
                                </td>
                                <td class="text-right">{{ number_format($item->grand_total, 2) }}</td>
                                <td class="text-right text-success">{{ number_format($item->paid_amount, 2) }}</td>
                                <td class="text-right text-danger">{{ number_format($item->due_amount, 2) }}</td>
                                <td class="text-center">
                                    @if($item->payment_status == 'Paid')
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

                                    <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>

                                      <div class="dropdown-menu" role="menu" style="">

                                        <a class="dropdown-item" href="{{ route('purchase.print',['id'=>$item['id']]) }}">
                                          <i class="fa fa-print"></i> Print Invoice
                                        </a>

                                        @if($item->due_amount > 0)
                                            <a class="dropdown-item text-primary" href="javascript:void(0)" 
                                               onclick="openPaymentModal({{ $item->id }}, '{{ $item->invoice_no }}', {{ $item->due_amount }})">
                                                <i class="fa fa-money-bill-wave"></i> Pay Now
                                            </a>
                                        @endif

                                         <a class="dropdown-item" href="{{ route('purchase.show',['id'=>$item['id']]) }}">
                                          <i class="fa fa-eye"></i> Show
                                        </a>

                                       <form id="deleteForm-{{ $item['id'] }}" action="{{ route('purchase.delete', ['id'=>$item['id']]) }}" method="POST" style="display:inline;">
                                          @csrf
                                          @method('DELETE')
                                          <a href="javascript:void(0)" onclick="confirmDelete({{ $item['id'] }})" class="dropdown-item">
                                              <i class="fa fa-trash"></i> Delete
                                          </a>
                                      </form>


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

function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this purchase? This will adjust stock and ledger!')) {
        document.getElementById('deleteForm-' + id).submit();
    }
}
</script>
@endpush