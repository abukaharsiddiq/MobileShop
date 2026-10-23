@extends('back-end.master')

@section('admin-title')
  {{ $name }}
@endsection

@section('name')
{{ $name }}
@endsection

@section('title')
 {{ $title }}
@endsection

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header">
            <h6 class="m-0 font-weight-bold text-primary">Expense by Payment Method</h6>
        </div>
        <div class="card-body">
            <form action="" method="GET" class="mb-4">
                <div class="row">
                    <div class="col-md-4">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="{{ $start_date }}">
                    </div>
                    <div class="col-md-4">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" value="{{ $end_date }}">
                    </div>
                    <div class="col-md-2">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-block">Search</button>
                    </div>
                </div>
            </form>

            <div class="row">
                <div class="col-md-12">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Payment Method</th>
                                    <th class="text-center">Transactions</th>
                                    <th class="text-right">Total Spent</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($methodData as $data)
                                <tr>
                                    <td class="font-weight-bold text-uppercase">
                                        {{ $data->payment_method }}
                                    </td>
                                    <td class="text-center">{{ $data->total_transactions }} times</td>
                                    <td class="text-right font-weight-bold"> {{ number_format($data->total_spent, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-ligh text-dark">
                                <tr>
                                    <td colspan="2" class="text-right">Total Expense:</td>
                                    <td class="text-right font-weight-bold"> {{ number_format($grandTotal, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="col-md-5 d-none">
                    <div class="alert alert-info border-left-info shadow">
                        <h5 class="font-weight-bold"><i class="fa fa-info-circle"></i> Daily Cash Matching</h5>
                        <hr>
                        @php
                            $cashSpent = $methodData->where('payment_method', 'Cash')->first()->total_spent ?? 0;
                        @endphp
                        <p>আজকের নগদ খরচ হয়েছে: <strong>৳ {{ number_format($cashSpent, 2) }}</strong></p>
                        <p class="mb-0 text-dark">দয়া করে আপনার ড্রয়ারের নগদ টাকা মেলানোর সময় এই পরিমাণটি বাদ দিয়ে হিসাব করুন।</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection