@extends('back-end.master')

@section('admin-content')
<style>
    /* ভয়েস ওভারলে ডিজাইন */
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
        width: 120px;
        height: 120px;
        background: #ff4b2b;
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        margin-bottom: 25px;
        animation: pulse-red 1.5s infinite;
    }

    @keyframes pulse-red {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0.7); }
        70% { transform: scale(1.1); box-shadow: 0 0 0 30px rgba(255, 75, 43, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0); }
    }

    .status-badge {
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 12px;
    }

    /* শুরুতে টেবিল কন্টেইনার হাইড রাখা */
    #mainTableCard {
        display: none;
    }

    #welcomeBox {
        text-align: center;
        padding: 80px 20px;
        background: #f8f9fa;
        border: 2px dashed #dee2e6;
        border-radius: 15px;
    }
</style>

<div id="voiceOverlay">
    <div class="mic-animation">
        <svg width="60" height="60" viewBox="0 0 16 16" fill="white" xmlns="http://www.w3.org/2000/svg">
            <path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>
            <path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/>
        </svg>
    </div>
    <h2 class="mb-2">I am Listening...</h2>
    <p>Try saying: <b>"Samsung"</b>, <b>"Red"</b>, or <b>"Model A10"</b></p>
    <button class="btn btn-outline-light mt-3" id="cancelVoice" style="border-radius: 20px;">Cancel Search</button>
</div>

<div class="container-fluid">
    <div class="row justify-content-center mb-4">
        <div class="col-md-6 text-center">
            <h4 class="mb-3">Advanced Smart Search</h4>
            <div class="input-group shadow-sm" style="border-radius: 50px; overflow: hidden; border: 2px solid #007bff;">
                <input type="text" id="voiceInput" class="form-control border-0" placeholder="What are you looking for?" style="padding: 15px 25px; outline: none;">
                <div class="input-group-append">
                    <button class="btn btn-primary px-4" type="button" id="startMicBtn">
                        <i class="fas fa-microphone"></i> Start Voice
                    </button>
                </div>
            </div>
            <small class="text-muted mt-2 d-block">Voice search supports Name, Model, and Color.</small>
        </div>
    </div>

    <div id="welcomeBox">
        <i class="fas fa-search fa-4x text-primary mb-3"></i>
        <h3>No Data to Show</h3>
        <p class="text-muted">Please use the voice search to find products instantly.</p>
    </div>

    <div class="card shadow-sm" id="mainTableCard">
        <div class="card-body">
            <table class="table table-bordered table-striped zero_config">
                <thead class="bg-primary text-white">
                    <tr>
                        <th>#</th>
                        <th>Product Name</th>
                        <th>Model</th>
                        <th>Category/Brand</th>
                        <th>Color</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="productTableBody">
                    @foreach($products as $product)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $product->product_name }}</td>
                        <td><strong>{{ $product->model_number }}</strong></td>
                        <td>
                            {{ $product->category->name ?? '-' }} <br>
                            <small class="text-muted">{{ $product->brand->brand_name ?? '-' }}</small>
                        </td>
                        <td>{{ $product->color->color_name ?? '-' }}</td>
                        <td>
                            <a href="#" class="btn btn-sm btn-info"><i class="fa fa-eye"></i></a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <button class="btn btn-secondary mt-3" onclick="location.reload();">Clear Results</button>
        </div>
    </div>
</div>

<script>
    const startMicBtn = document.getElementById('startMicBtn');
    const cancelVoice = document.getElementById('cancelVoice');
    const voiceOverlay = document.getElementById('voiceOverlay');
    const voiceInput = document.getElementById('voiceInput');
    const welcomeBox = document.getElementById('welcomeBox');
    const mainTableCard = document.getElementById('mainTableCard');

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognition) {
        const recognition = new SpeechRecognition();
        recognition.lang = 'en-US';
        recognition.continuous = false;
        recognition.interimResults = false;

        startMicBtn.addEventListener('click', () => {
            recognition.start();
        });

        recognition.onstart = () => {
            voiceOverlay.style.display = 'flex';
        };

        recognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript.toLowerCase();
            voiceInput.value = transcript;

            // টেবিল শো করানো
            welcomeBox.style.display = 'none';
            mainTableCard.style.display = 'block';

            // DataTable এ অ্যাডভান্স ফিল্টারিং
            if ($.fn.DataTable.isDataTable('.zero_config')) {
                const table = $('.zero_config').DataTable();
                
                // গ্লোবাল সার্চ (এটি নাম, মডেল, ক্যাটাগরি সব কলামে কাজ করবে)
                table.search(transcript).draw();

                // যদি রেজাল্ট না পাওয়া যায়
                if (table.rows({filter: 'applied'}).count() === 0) {
                    setTimeout(() => {
                        alert("No match found for: " + transcript);
                    }, 500);
                }
            }
            voiceOverlay.style.display = 'none';
        };

        recognition.onspeechend = () => {
            recognition.stop();
            voiceOverlay.style.display = 'none';
        };

        recognition.onerror = (event) => {
            voiceOverlay.style.display = 'none';
            console.error("Speech recognition error:", event.error);
        };

        cancelVoice.addEventListener('click', () => {
            recognition.stop();
            voiceOverlay.style.display = 'none';
        });

    } else {
        startMicBtn.innerHTML = "Not Supported";
        startMicBtn.disabled = true;
    }

    
</script>
@endsection