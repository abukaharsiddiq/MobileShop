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
    <div class="card mb-4">
        <div class="card-header bg-light py-1 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Monthly Expense Summary (Year: {{ $year }})</h6>
            <form action="" method="GET" class="form-inline">
                <select name="year" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                    @for($i = date('Y'); $i >= (date('Y') - 1); $i--)
                        <option value="{{ $i }}" {{ $year == $i ? 'selected' : '' }}>{{ $i }}</option>
                    @endfor
                </select>
                <button type="button" class="btn btn-sm btn-secondary" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
            </form>
        </div>
        
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped text-center">
                    <thead class="bg-primary text-white">
                        <tr>
                            <th>Month</th>
                            <th>Expense ({{ $year }})</th>
                            <th>Expense ({{ $year - 1 }})</th>
                            <th>Year-on-Year Comparison</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for($m = 1; $m <= 12; $m++)
                            @php
                                $monthName = date('F', mktime(0, 0, 0, $m, 1));
                                $currentAmount = $currentYearData[$m]->total_amount ?? 0;
                                $lastYearAmount = $lastYearData[$m]->total_amount ?? 0;
                                
                                $diff = $currentAmount - $lastYearAmount;
                                $percentage = ($lastYearAmount > 0) ? ($diff / $lastYearAmount) * 100 : 0;
                            @endphp
                            <tr>
                                <td class="font-weight-bold">{{ $monthName }}</td>
                                <td class="text-primary font-weight-bold">{{ number_format($currentAmount, 2) }}</td>
                                <td class="text-muted">{{ number_format($lastYearAmount, 2) }}</td>
                                <td>
                                    @if($currentAmount > 0)
                                        @if($diff > 0)
                                            <span class="text-danger font-weight-bold">
                                                <i class="fa fa-arrow-up"></i> {{ number_format($percentage, 1) }}% Increase
                                            </span>
                                        @elseif($diff < 0)
                                            <span class="text-success font-weight-bold">
                                                <i class="fa fa-arrow-down"></i> {{ number_format(abs($percentage), 1) }}% Decrease
                                            </span>
                                        @else
                                            <span class="text-secondary">No Change</span>
                                        @endif
                                    @else
                                        <span class="text-light">---</span>
                                    @endif
                                </td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection