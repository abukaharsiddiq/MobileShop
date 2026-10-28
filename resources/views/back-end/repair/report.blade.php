@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $t = $report['totals']; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Repair Report</h5>
            <form method="GET" class="form-inline">
                <input type="date" name="from_date" class="form-control form-control-sm mr-2" value="{{ $from }}">
                <input type="date" name="to_date" class="form-control form-control-sm mr-2" value="{{ $to }}">
                <button class="btn btn-sm btn-primary">Show</button>
            </form>
        </div>
        <div class="card-body">
            <div class="row">
                @foreach([
                    ['Delivered jobs', $t['jobs'], false],
                    ['Service charge (labour)', $t['service_charge'], true],
                    ['Parts sales', $t['parts_sales'], true],
                    ['Parts cost', $t['parts_cost'], true],
                    ['Parts margin', $t['parts_margin'], true],
                    ['Gross profit', $t['gross_profit'], true],
                ] as [$label, $value, $money])
                    <div class="col-md-2 col-6">
                        <div class="card card-outline card-primary"><div class="card-body py-2">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="h5 m-0">{{ $money ? number_format($value, 2) : $value }}</div>
                        </div></div>
                    </div>
                @endforeach
            </div>
            <p class="small text-muted">
                Discount {{ number_format($t['discount'], 2) }} · billed {{ number_format($t['billed'], 2) }} · still due {{ number_format($t['due'], 2) }} ·
                cancelled {{ $report['cancelled']['jobs'] }} (charges {{ number_format($report['cancelled']['charges'], 2) }}) ·
                open now {{ $report['open']['jobs'] }} (advance held {{ number_format($report['open']['advance'], 2) }}).
                Gross profit = billed - parts cost.
            </p>

            <h6>By technician</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="thead-light"><tr><th>Technician</th><th class="text-right">Jobs</th><th class="text-right">Service charge</th>
                        <th class="text-right">Parts sales</th><th class="text-right">Parts cost</th><th class="text-right">Parts margin</th><th class="text-right">Discount</th><th class="text-right">Gross profit</th></tr></thead>
                    <tbody>
                        @forelse($report['by_technician'] as $name => $row)
                            <tr><td>{{ $name }}</td><td class="text-right">{{ $row['jobs'] }}</td>
                                @foreach(['service_charge', 'parts_sales', 'parts_cost', 'parts_margin', 'discount', 'gross_profit'] as $col)
                                    <td class="text-right">{{ number_format($row[$col], 2) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted">No delivered jobs in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
