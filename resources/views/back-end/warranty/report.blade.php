@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $r = $report; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Warranty Report</h5>
            <form method="GET" class="form-inline d-print-none">
                <input type="date" name="from_date" class="form-control form-control-sm mr-1" value="{{ $from }}">
                <input type="date" name="to_date" class="form-control form-control-sm mr-1" value="{{ $to }}">
                <button class="btn btn-sm btn-primary mr-1">Show</button>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i></button>
            </form>
        </div>
        <div class="card-body">
            <p class="small text-muted">Warranties of sales from {{ \Carbon\Carbon::parse($from)->format('d M Y') }} to {{ \Carbon\Carbon::parse($to)->format('d M Y') }}; claims reported in the same period.</p>
            <div class="row">
                @foreach(\App\Models\Warranty::STATUS_LABELS as $key => $label)
                    <div class="col-md-2 col-4 mb-3">
                        <div class="border rounded p-2 text-center">
                            <small class="text-muted">{{ $label }}</small>
                            <h4 class="mb-0">
                                <a href="{{ route('warranty.index', ['status' => $key, 'from_date' => $from, 'to_date' => $to]) }}">{{ $r['by_status'][$key] ?? 0 }}</a>
                            </h4>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="row">
                <div class="col-md-4">
                    <h6>Claims ({{ $r['claims'] }}, {{ $r['claim_rate'] }}% of warranties)</h6>
                    <table class="table table-sm table-bordered">
                        @forelse($r['by_claim'] as $status => $count)
                            <tr><td>{{ \App\Models\WarrantyClaim::LABELS[$status] ?? $status }}</td><td class="text-right">{{ $count }}</td></tr>
                        @empty
                            <tr><td class="text-muted">No claims</td></tr>
                        @endforelse
                    </table>
                </div>
                <div class="col-md-4">
                    <h6>Problems</h6>
                    <table class="table table-sm table-bordered">
                        @foreach($r['by_reason'] as $reason => $count)
                            <tr><td>{{ config('warranty.claim_reasons')[$reason] ?? $reason }}</td><td class="text-right">{{ $count }}</td></tr>
                        @endforeach
                    </table>
                </div>
                <div class="col-md-4">
                    <h6>Results</h6>
                    <table class="table table-sm table-bordered">
                        @foreach($r['by_resolution'] as $resolution => $count)
                            <tr><td>{{ \App\Models\WarrantyClaim::RESOLUTIONS[$resolution] ?? ucfirst($resolution) }}</td><td class="text-right">{{ $count }}</td></tr>
                        @endforeach
                    </table>
                    <h6>By type</h6>
                    <table class="table table-sm table-bordered">
                        @foreach($r['by_type'] as $type => $count)
                            <tr><td>{{ ucfirst($type) }}</td><td class="text-right">{{ $count }}</td></tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
