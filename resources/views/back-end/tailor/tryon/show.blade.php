@extends('back-end.master')

@section('admin-content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-magic text-primary me-2"></i> Virtual Try-On Studio</h5>
                </div>
                <div class="card-body text-center">
                    <div id="tryon-placeholder" class="mb-4 border rounded p-3 bg-light d-flex align-items-center justify-content-center" style="min-height: 400px;">
                        <img id="result-img" src="{{ asset($product->garment_image_transparent) }}" class="img-fluid rounded shadow-sm d-none" style="max-height: 500px;">
                        <div id="initial-msg">
                            <i class="fas fa-user-tie fa-4x text-muted mb-3"></i>
                            <p class="text-muted">Upload your photo to see how <br><strong id="selected-dress-name">{{ $product->dress_name }}</strong> looks on you!</p>
                        </div>
                        <div id="loading-spinner" class="d-none">
                            <div class="spinner-grow text-primary" role="status"></div>
                            <p class="mt-2 fw-bold">AI is tailoring your dress... 15-20s</p>
                        </div>
                    </div>

                    <div class="upload-box p-4 border-dashed rounded mb-3">
                        <input type="file" id="customer_image" class="form-control mb-3" accept="image/*">
                        <button onclick="startTryOn()" id="try-btn" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-camera me-2"></i> Try This Dress Now
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Select Another Design</h5>
                </div>
                <div class="card-body p-2">
                    <div class="row g-2" style="max-height: 600px; overflow-y: auto;">
                        @foreach($all_products as $item)
                        <div class="col-6">
                            <div class="card h-100 border-0 shadow-xs product-card" 
                                 onclick="changeProduct('{{ $item->id }}', '{{ $item->dress_name }}', '{{ asset($item->garment_image_transparent) }}')"
                                 style="cursor: pointer;">
                                <img src="{{ asset($item->garment_image_transparent) }}" class="card-img-top p-2" style="height: 120px; object-fit: contain;">
                                <div class="card-body p-2 text-center">
                                    <p class="small mb-0 fw-bold">{{ $item->dress_name }}</p>
                                    <span class="text-primary small">৳{{ $item->price }}</span>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
let currentProductId = '{{ $product->id }}';

function changeProduct(id, name, img) {
    currentProductId = id;
    document.getElementById('selected-dress-name').innerText = name;
    // প্রিভিউ আপডেট
    let resultImg = document.getElementById('result-img');
    resultImg.src = img;
    resultImg.classList.remove('d-none');
    document.getElementById('initial-msg').classList.add('d-none');
    
    // হাইলাইট সিলেক্টেড কার্ড
    document.querySelectorAll('.product-card').forEach(c => c.classList.remove('border-primary'));
}

function startTryOn() {
    let file = document.getElementById('customer_image').files[0];
    if(!file) return Swal.fire('Error', 'Please upload your front-facing photo!', 'error');

    let formData = new FormData();
    formData.append('customer_image', file);
    formData.append('product_id', currentProductId);
    formData.append('_token', '{{ csrf_token() }}');

    // UI আপডেট
    document.getElementById('loading-spinner').classList.remove('d-none');
    document.getElementById('initial-msg').classList.add('d-none');
    document.getElementById('result-img').classList.add('d-none');
    document.getElementById('try-btn').disabled = true;

    fetch('{{ route('tailor.product.process.tryon') }}', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('loading-spinner').classList.add('d-none');
        document.getElementById('try-btn').disabled = false;
        
        if(data.success) {
            let resultImg = document.getElementById('result-img');
            resultImg.src = data.image;
            resultImg.classList.remove('d-none');
            Swal.fire('Success!', 'Your Virtual Look is Ready!', 'success');
        } else {
            Swal.fire('Failed', data.message || 'AI Process Error', 'error');
            document.getElementById('initial-msg').classList.remove('d-none');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Something went wrong!', 'error');
    });
}
</script>

<style>
    .border-dashed { border: 2px dashed #dee2e6; }
    .product-card:hover { border: 1px solid #007bff; transform: translateY(-3px); transition: 0.3s; }
    .shadow-xs { box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
</style>
@endsection