@extends('back-end.master')

@section('admin-title', 'Create Measurement')

@section('admin-content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title"><i class="fas fa-ruler-combined me-2"></i> Standard Measurement Form (Universal)</h5>
                </div>
                
                <form action="{{ route('tailor.measurement.store') }}" method="POST">
                    @csrf
                    <div class="card-body p-4">
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-dark">Profile Name / কাস্টমারের নাম</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-user-tag text-primary"></i></span>
                                    <input type="text" name="profile_name" class="form-control form-control-lg" 
                                           placeholder="উদা: নিজের মাপ / কাস্টমারের নাম" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">Unit (একক)</label>
                                <select class="form-select form-select-lg bg-light" disabled>
                                    <option>Inches (ইঞ্চি)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-xl-6">
                                <div class="p-3 border rounded-3 bg-white h-100 shadow-sm">
                                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">
                                        <i class="fas fa-tshirt me-2"></i> Upper Body (জামা / পাঞ্জাবী / কামিজ)
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Length (লম্বা)</label>
                                            <input type="number" step="0.1" name="length" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Body/Chest (বুক)</label>
                                            <input type="number" step="0.1" name="body_chest" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Waist (কোমর)</label>
                                            <input type="number" step="0.1" name="waist" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Hip (হিপ/ঘের)</label>
                                            <input type="number" step="0.1" name="hip" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Shoulder (পুঁট/কাঁধ)</label>
                                            <input type="number" step="0.1" name="shoulder" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Sleeve (হাতা)</label>
                                            <input type="number" step="0.1" name="sleeve_length" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Neck (কলার/গলা)</label>
                                            <input type="number" step="0.1" name="neck" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Armhole (বগল)</label>
                                            <input type="number" step="0.1" name="armhole" class="form-control" placeholder="0.0">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-6">
                                <div class="p-3 border rounded-3 bg-white h-100 shadow-sm">
                                    <h6 class="fw-bold text-success mb-3 border-bottom pb-2">
                                        <i class="fas fa-universal-access me-2"></i> Lower Body (প্যান্ট / পায়জামা / সালোয়ার)
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Pant Length (লম্বা)</label>
                                            <input type="number" step="0.1" name="pant_length" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Pant Waist (কোমর)</label>
                                            <input type="number" step="0.1" name="pant_waist" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Thigh (রান)</label>
                                            <input type="number" step="0.1" name="thigh" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Ankle/Bottom (মুহুরী)</label>
                                            <input type="number" step="0.1" name="ankle" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Knee (হাঁটু)</label>
                                            <input type="number" step="0.1" name="knee" class="form-control" placeholder="0.0">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-semibold">Crotch/Fly (হাই)</label>
                                            <input type="number" step="0.1" name="crotch" class="form-control" placeholder="0.0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="fw-bold small">Special Instructions / বিশেষ দ্রষ্টব্য (ঐচ্ছিক)</label>
                            <textarea name="special_note" class="form-control" rows="2" placeholder="যেমন: কলার গোল হবে, পকেটে চেইন হবে ইত্যাদি..."></textarea>
                        </div>
                    </div>
                    
                    <div class="card-footer bg-light py-3 d-flex justify-content-between align-items-center">
                        <small class="text-muted"><i class="fas fa-info-circle"></i> সব মাপ ইঞ্চিতে ইনপুট দিন।</small>
                        <div>
                            <a href="{{ route('tailor.measurement.index') }}" class="btn btn-outline-secondary me-2">Cancel</a>
                            <button type="submit" class="btn btn-dark btn-lg px-5">
                                <i class="fas fa-save me-2"></i> Save Measurements
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .form-control { border-radius: 8px; border: 1px solid #ced4da; padding: 0.6rem; transition: all 0.2s; }
    .form-control:focus { border-color: #333; box-shadow: 0 0 0 0.25rem rgba(0,0,0,0.05); }
    .card { border-radius: 15px; }
    label { color: #555; }
    h6 { letter-spacing: 0.5px; }
</style>
@endsection