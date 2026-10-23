@extends('back-end.master')

@section('admin-title') Product List @endsection
@section('name') Products @endsection
@section('title') Products List @endsection

@section('admin-content')
<style>
    /* ভয়েস ওভারলে এবং এনিমেশন */
    #voiceOverlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.85);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        color: white;
        text-align: center;
        flex-direction: column;
    }
    .mic-animation {
        width: 100px; height: 100px;
        background: #ff4b2b; border-radius: 50%;
        display: flex; justify-content: center; align-items: center;
        margin-bottom: 20px; animation: pulse-red 1.5s infinite;
    }
    @keyframes pulse-red {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0.7); }
        70% { transform: scale(1.1); box-shadow: 0 0 0 20px rgba(255, 75, 43, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0); }
    }
    #mainTableCard { display: none; } /* শুরুতে টেবিল লুকানো থাকবে */
</style>

<div id="voiceOverlay">
    <div class="mic-animation">
        <svg width="50" height="50" viewBox="0 0 16 16" fill="white" xmlns="http://www.w3.org/2000/svg">
            <path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>
            <path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/>
        </svg>
    </div>
    <h2>Listening...</h2>
    <p>Try: "Samsung", "Delete Product One" or "Delete iPhone 15"</p>
</div>

<div class="container-fluid">
    <div class="row justify-content-center mb-4">
        <div class="col-md-6 text-center">
            <div class="input-group shadow-sm" style="border-radius: 50px; overflow: hidden; border: 2px solid #007bff;">
                <input type="text" id="voiceInput" class="form-control border-0" placeholder="Search or Command with voice..." style="padding: 12px 20px;">
                <div class="input-group-append">
                    <button class="btn btn-primary px-4" type="button" id="startMicBtn">
                        <i class="fas fa-microphone"></i> Start Voice
                    </button>
                </div>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div id="welcomeBox" class="text-center py-5 bg-light border rounded">
        <i class="fas fa-search fa-3x text-muted mb-3"></i>
        <h4>No Data to Show. Please use Voice Search.</h4>
    </div>

    <div class="card shadow-sm" id="mainTableCard">
        <div class="card-header d-flex justify-content-between">
            <h5 class="mb-0">All Products</h5>
            <button class="btn btn-sm btn-secondary" onclick="location.reload()">Clear Results</button>
        </div>
        <div class="card-body">
            <table class="table table-bordered table-striped zero_config">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Name/Model</th>
                        <th>Category/Brand</th>
                        <th>RAM/ROM</th>
                        <th>Color</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="productTableBody">
                    @forelse($products as $product)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ ucfirst(str_replace('_',' ',$product->type)) }}</td>
                        <td>{{ $product->product_name }}<br/><b>{{ $product->model_number }}</b></td>
                        <td>{{ $product->category->name ?? '-' }}<br/>{{ $product->brand->brand_name ?? '-' }}</td>
                        <td>{{ $product->ram->ram_name ?? '-' }}<br/>{{ $product->rom->rom_name ?? '-' }}</td>
                        <td>{{ $product->color->color_name ?? '-' }}</td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" class="status-switch" data-id="{{ $product->id }}" {{ $product->status == '1' ? 'checked' : '' }}>
                                <span class="slider round"></span>
                            </label>
                        </td>
                        <td>
                            <form action="{{ route('product.delete', $product->id) }}" method="POST" class="d-inline delete-form">
                                @csrf @method('DELETE')
                                <button type="button" class="btn btn-sm btn-danger delete-btn" data-name="{{ $product->product_name }}" onclick="confirmDelete(this)">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center">No Products Found</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    const startMicBtn = document.getElementById('startMicBtn');
    const voiceOverlay = document.getElementById('voiceOverlay');
    const voiceInput = document.getElementById('voiceInput');
    const welcomeBox = document.getElementById('welcomeBox');
    const mainTableCard = document.getElementById('mainTableCard');
    
    // Number Map for row selection
    const numberMap = { "one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7, "eight": 8, "nine": 9, "ten": 10 };

    function speakResult(message) {
        const speech = new SpeechSynthesisUtterance();
        speech.text = message;
        speech.rate = 1;
        window.speechSynthesis.speak(speech);
    }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognition) {
        const recognition = new SpeechRecognition();
        recognition.lang = 'en-US';

        startMicBtn.addEventListener('click', () => recognition.start());

        recognition.onstart = () => { voiceOverlay.style.display = 'flex'; };

        recognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript.toLowerCase();
            voiceInput.value = transcript;
            voiceOverlay.style.display = 'none';

            welcomeBox.style.display = 'none';
            mainTableCard.style.display = 'block';

            const table = $('.zero_config').DataTable();

            // --- ADVANCED DELETE LOGIC (NAME OR NUMBER) ---
            if (transcript.includes("delete")) {
                let targetDeleteBtn = null;
                const words = transcript.split(" ");
                const lastWord = words[words.length - 1];
                const rowNumber = numberMap[lastWord] || parseInt(lastWord);

                if (rowNumber) {
                    // Method A: Delete by Row Number
                    const targetRow = document.querySelector(`#productTableBody tr:nth-child(${rowNumber})`);
                    targetDeleteBtn = targetRow ? targetRow.querySelector('.delete-btn') : null;
                } else {
                    // Method B: Delete by Product Name
                    const searchName = transcript.replace("delete product", "").replace("delete", "").trim();
                    const allBtns = document.querySelectorAll('.delete-btn');
                    
                    allBtns.forEach(btn => {
                        const pName = btn.getAttribute('data-name').toLowerCase();
                        if (pName.includes(searchName) || searchName.includes(pName)) {
                            targetDeleteBtn = btn;
                        }
                    });
                }

                if (targetDeleteBtn) {
                    const finalName = targetDeleteBtn.getAttribute('data-name');
                    speakResult(`Are you sure you want to delete ${finalName}?`);
                    
                    setTimeout(() => {
                        if (confirm(`Confirm Deletion of: ${finalName}`)) {
                            targetDeleteBtn.closest('form').submit();
                        }
                    }, 500);
                } else {
                    speakResult("Sorry, I couldn't find that product.");
                }
            } 
            // --- STANDARD SEARCH LOGIC ---
            else {
                table.search(transcript).draw();
                const count = table.rows({ filter: 'applied' }).count();
                speakResult(`Found ${count} products.`);
            }
        };

        recognition.onspeechend = () => { voiceOverlay.style.display = 'none'; };
        recognition.onerror = () => { voiceOverlay.style.display = 'none'; };
    }

    // Manual Delete Function
    function confirmDelete(btn) {
        const name = btn.getAttribute('data-name');
        if (confirm(`Are you sure you want to delete ${name}?`)) {
            btn.closest('form').submit();
        }
    }
</script>
@endpush