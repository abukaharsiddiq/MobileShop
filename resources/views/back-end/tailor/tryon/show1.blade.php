@extends('back-end.master')
@section('admin-content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 text-center">
            <h2 class="mb-4">Virtual Try-On: {{ $product->dress_name }}</h2>
            
            <div id="loading" class="d-none mb-3">
                <div class="spinner-border text-primary" role="status"></div>
                <p>AI is dressing you up... Please wait.</p>
            </div>

            <div id="result-container" class="mb-4 border rounded p-2 bg-light d-none">
                <img id="result-img" src="" class="img-fluid rounded shadow" style="max-height: 500px;">
            </div>

            <div class="card border-primary shadow-sm">
                <div class="card-body">
                    <h5>Upload your photo (Front Facing)</h5>
                    <input type="file" id="customer_image" class="form-control mb-3">
                    <button onclick="startTryOn()" class="btn btn-primary btn-lg w-100">Try This Dress Now</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function startTryOn() {
    let file = document.getElementById('customer_image').files[0];
    if(!file) return alert('Please upload a photo first!');

    let formData = new FormData();
    formData.append('customer_image', file);
    formData.append('product_id', '{{ $product->id }}');
    formData.append('_token', '{{ csrf_token() }}');

    document.getElementById('loading').classList.remove('d-none');
    
    fetch('{{ route('tailor.product.process.tryon') }}', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('loading').classList.add('d-none');
        if(data.success) {
            document.getElementById('result-container').classList.remove('d-none');
            document.getElementById('result-img').src = data.image;
        }
    });
}
</script>
@endsection