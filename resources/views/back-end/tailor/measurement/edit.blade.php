@extends('back-end.master')
@section('admin-content')
<div class="container-fluid py-4">
    <div class="card shadow-lg border-0 rounded-4">
        <div class="card-header bg-dark py-3">
            <h5 class="mb-0 text-white"><i class="fas fa-edit me-2"></i> Update Measurement: {{ $measurement->profile_name }}</h5>
        </div>
        <form action="{{ route('tailor.measurement.update', $measurement->id) }}" method="POST">
            @csrf
            <div class="card-body p-4">
                <div class="row mb-4">
                    <div class="col-md-12">
                        <label class="form-label fw-bold">Profile Name / কাস্টমারের নাম</label>
                        <input type="text" name="profile_name" class="form-control form-control-lg" value="{{ $measurement->profile_name }}" required>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-xl-6">
                        <div class="p-3 border rounded-3 bg-white shadow-sm">
                            <h6 class="fw-bold text-primary mb-3">Upper Body</h6>
                            <div class="row g-3">
                                @foreach(['length', 'body_chest', 'waist', 'hip', 'shoulder', 'sleeve_length', 'neck', 'armhole'] as $field)
                                <div class="col-md-6">
                                    <label class="small fw-semibold text-capitalize">{{ str_replace('_', ' ', $field) }}</label>
                                    <input type="number" step="0.1" name="{{ $field }}" class="form-control" value="{{ $measurement->$field }}">
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="p-3 border rounded-3 bg-white shadow-sm">
                            <h6 class="fw-bold text-success mb-3">Lower Body</h6>
                            <div class="row g-3">
                                @foreach(['pant_length', 'pant_waist', 'thigh', 'ankle', 'knee', 'crotch'] as $field)
                                <div class="col-md-6">
                                    <label class="small fw-semibold text-capitalize">{{ str_replace('_', ' ', $field) }}</label>
                                    <input type="number" step="0.1" name="{{ $field }}" class="form-control" value="{{ $measurement->$field }}">
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="fw-bold small">Special Instructions</label>
                    <textarea name="special_note" class="form-control" rows="2">{{ $measurement->special_note }}</textarea>
                </div>
            </div>
            <div class="card-footer bg-light py-3 d-flex justify-content-end">
                <a href="{{ route('tailor.measurement.index') }}" class="btn btn-outline-secondary me-2">Cancel</a>
                <button type="submit" class="btn btn-primary px-5 shadow">Update Changes</button>
            </div>
        </form>
    </div>
</div>
@endsection