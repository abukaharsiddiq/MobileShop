@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card col-xl-9 px-0">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Trial Balance as of {{ \Carbon\Carbon::parse($asOf)->format('d M Y') }}</h5>
            <form method="GET" class="form-inline d-print-none">
                <input type="date" name="as_of" class="form-control form-control-sm mr-2" value="{{ $asOf }}">
                <button class="btn btn-sm btn-primary mr-1">Show</button>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i></button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light"><tr><th>Code</th><th>Account</th><th>Type</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
                <tbody>
                    @foreach($report['rows'] as $row)
                        <tr>
                            <td>{{ $row['account']->code }}</td>
                            <td><a href="{{ route('finance.accounts.statement', $row['account']->id) }}">{{ $row['account']->name }}</a></td>
                            <td>{{ ucfirst($row['account']->type) }}</td>
                            <td class="text-right">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '' }}</td>
                            <td class="text-right">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="font-weight-bold">
                    <tr class="{{ $report['debit'] == $report['credit'] ? 'table-success' : 'table-danger' }}">
                        <td colspan="3" class="text-right">Total</td>
                        <td class="text-right">{{ number_format($report['debit'], 2) }}</td>
                        <td class="text-right">{{ number_format($report['credit'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
