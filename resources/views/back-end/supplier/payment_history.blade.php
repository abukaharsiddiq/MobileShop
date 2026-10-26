@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $canVoid = auth()->user()->can('void supplier payment'); @endphp
<div class="container-fluid">
    <div class="card shadow">

          <div class="card-header">
          <h1 class="card-title">{{ $supplier->name }} Payment History</h1>
          <div>
            @if(auth()->user()->canAny(['create supplier payment', 'update purchase']))
              <a href="{{ route('supplier.payment.create', $supplier->id) }}" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> New Payment</a>
            @endif
            <a href="{{ route('supplier.show', $supplier->id) }}" class="btn btn-sm back-btn">
               <i class="fa fa-arrow-left"></i> Profile
            </a>
          </div>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Payment No</th>
                            <th>Type</th>
                            <th>Invoice</th>
                            <th>Method / Reference</th>
                            <th>Note</th>
                            <th>By</th>
                            <th class="text-right">Amount</th>
                            @if($canVoid)<th></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                        <tr class="{{ $payment->isVoided() ? 'text-muted' : '' }}">
                            <td>{{ $payment->payment_date->format('d-m-Y') }}</td>
                            <td>{{ $payment->payment_no }}</td>
                            <td>
                                <span class="badge {{ $payment->isRefund() ? 'badge-warning' : 'badge-success' }}">{{ $payment->isRefund() ? 'Refund received' : 'Payment' }}</span>
                                @if($payment->isVoided())
                                    <br><span class="badge badge-danger">Void</span>
                                    <small>{{ $payment->voided_at->format('d-m-Y') }} {{ $payment->voider?->name }}: {{ $payment->void_reason }}</small>
                                @endif
                            </td>
                            <td>
                                @if($payment->purchase)
                                    <a href="{{ route('purchase.show', $payment->purchase_id) }}">{{ $payment->purchase->invoice_no }}</a>
                                @else
                                    <small class="text-muted">{{ $payment->isRefund() ? '-' : 'On account' }}</small>
                                @endif
                            </td>
                            <td>
                                {{ $payment->payment_method }}{{ $payment->payment_account ? ' · ' . $payment->payment_account : '' }}
                                @if($payment->reference_no)<br><small>Ref: {{ $payment->reference_no }}</small>@endif
                            </td>
                            <td><small>{{ $payment->note }}</small></td>
                            <td><small>{{ $payment->creator?->name ?? '-' }}</small></td>
                            <td class="text-right font-weight-bold">
                                @if($payment->isVoided())<del>{{ number_format($payment->amount, 2) }}</del>@else{{ number_format($payment->amount, 2) }}@endif
                            </td>
                            @if($canVoid)
                            <td class="text-center">
                                @unless($payment->isVoided())
                                <form action="{{ route('supplier.payment.void', $payment->id) }}" method="POST" class="js-void-form">
                                    @csrf
                                    <input type="hidden" name="void_reason">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Void"><i class="fa fa-ban"></i></button>
                                </form>
                                @endunless
                            </td>
                            @endif
                        </tr>
                        @empty
                        <tr>
                            <td colspan="{{ $canVoid ? 9 : 8 }}" class="text-center">No payment records found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                    @php $active = $payments->reject->isVoided(); @endphp
                    <tfoot class="bg-light">
                        <tr>
                            <th colspan="7" class="text-right">Total Paid:</th>
                            <th class="text-right text-primary">{{ number_format($active->where('type', 'payment')->sum('amount'), 2) }}</th>
                            @if($canVoid)<th></th>@endif
                        </tr>
                        @if($active->where('type', 'refund')->isNotEmpty())
                        <tr>
                            <th colspan="7" class="text-right">Total Refund Received:</th>
                            <th class="text-right">{{ number_format($active->where('type', 'refund')->sum('amount'), 2) }}</th>
                            @if($canVoid)<th></th>@endif
                        </tr>
                        @endif
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $('.js-void-form').on('submit', function (e) {
        const reason = prompt('Why are you voiding this payment? A reversing entry will be added to the ledger.');
        if (!reason || !reason.trim()) {
            e.preventDefault();
            return;
        }
        $(this).find('input[name=void_reason]').val(reason.trim());
    });
</script>
@endpush
