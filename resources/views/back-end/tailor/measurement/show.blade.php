@extends('back-end.master')
@section('admin-content')
<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fw-bold text-dark">Measurement Profile: {{ $measurement->profile_name }}</h5>
            <button onclick="window.print()" class="btn btn-sm btn-outline-primary d-print-none">
                <i class="fas fa-print me-1"></i> Print Measurement
            </button>
        </div>
        <div class="card-body bg-light p-4">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-bordered bg-white shadow-sm">
                        <thead class="table-primary">
                            <tr><th colspan="2">Upper Body (জামা)</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Length</td><td><strong>{{ $measurement->length ?? '-' }}″</strong></td></tr>
                            <tr><td>Chest</td><td><strong>{{ $measurement->body_chest ?? '-' }}″</strong></td></tr>
                            <tr><td>Waist</td><td><strong>{{ $measurement->waist ?? '-' }}″</strong></td></tr>
                            <tr><td>Hip</td><td><strong>{{ $measurement->hip ?? '-' }}″</strong></td></tr>
                            <tr><td>Shoulder</td><td><strong>{{ $measurement->shoulder ?? '-' }}″</strong></td></tr>
                            <tr><td>Sleeve</td><td><strong>{{ $measurement->sleeve_length ?? '-' }}″</strong></td></tr>
                            <tr><td>Neck</td><td><strong>{{ $measurement->neck ?? '-' }}″</strong></td></tr>
                            <tr><td>Armhole</td><td><strong>{{ $measurement->armhole ?? '-' }}″</strong></td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="col-md-6">
                    <table class="table table-bordered bg-white shadow-sm">
                        <thead class="table-success">
                            <tr><th colspan="2">Lower Body (প্যান্ট)</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Pant Length</td><td><strong>{{ $measurement->pant_length ?? '-' }}″</strong></td></tr>
                            <tr><td>Pant Waist</td><td><strong>{{ $measurement->pant_waist ?? '-' }}″</strong></td></tr>
                            <tr><td>Thigh</td><td><strong>{{ $measurement->thigh ?? '-' }}″</strong></td></tr>
                            <tr><td>Ankle</td><td><strong>{{ $measurement->ankle ?? '-' }}″</strong></td></tr>
                            <tr><td>Knee</td><td><strong>{{ $measurement->knee ?? '-' }}″</strong></td></tr>
                            <tr><td>Crotch/Hi</td><td><strong>{{ $measurement->crotch ?? '-' }}″</strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 p-3 bg-white border rounded shadow-sm">
                <h6 class="fw-bold text-danger"><i class="fas fa-exclamation-circle me-1"></i> Special Note:</h6>
                <p class="mb-0 text-muted">{{ $measurement->special_note ?? 'No special instructions provided.' }}</p>
            </div>
        </div>
    </div>
</div>
@endsection