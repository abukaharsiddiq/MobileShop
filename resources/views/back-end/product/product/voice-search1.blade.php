@extends('back-end.master')

@section('admin-content')
<style>
    #voiceOverlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.8);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        color: white;
        text-align: center;
        flex-direction: column;
    }
    
    .mic-animation {
        width: 100px;
        height: 100px;
        background: #ff4b2b;
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        margin-bottom: 20px;
        animation: pulse-red 1.5s infinite;
    }

    @keyframes pulse-red {
        0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 20px rgba(255, 75, 43, 0); }
        100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0); }
    }

    /* শুরুতে টেবিল বডি হাইড করে রাখার জন্য */
    #productTableBody {
        display: none;
    }
</style>

<div id="voiceOverlay">
    <div class="mic-animation">
        <svg width="50" height="50" viewBox="0 0 16 16" fill="white" xmlns="http://www.w3.org/2000/svg">
            <path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>
            <path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/>
        </svg>
    </div>
    <h3>Listening... Please Speak</h3>
    <p>Say a product name to see details</p>
</div>

<div class="container-fluid">
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-3">Voice Command Product Search</h5>
            <div style="max-width: 400px; display: flex; border: 2px solid #28a745; border-radius: 30px; overflow: hidden; background: #fff;">
                <input type="text" id="voiceInput" placeholder="Waiting for voice command..." style="border: none; padding: 12px 20px; flex: 1; outline: none;" readonly>
                <button type="button" id="startMic" style="background: #28a745; border: none; padding: 10px 25px; cursor: pointer;">
                    <svg width="20" height="20" viewBox="0 0 16 16" fill="white" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>
                        <path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="card-body">
            <div id="welcomeMessage" class="text-center py-5">
                <i class="fas fa-microphone-alt fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">No product selected. Click the mic to search.</h4>
            </div>

            <table id="myTable" class="table table-bordered zero_config1" style="display: none;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Name</th>
                        <th>Model</th>
                        <th>Category</th>
                    </tr>
                </thead>
                <tbody id="productTableBody">
                    @foreach($products as $product)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $product->product_name }}</td>
                        <td>{{ $product->model_number }}</td>
                        <td>{{ $product->category->name ?? '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const startMicBtn = document.getElementById('startMic');
    const voiceOverlay = document.getElementById('voiceOverlay');
    const voiceInput = document.getElementById('voiceInput');
    const productTable = document.getElementById('myTable');
    const welcomeMessage = document.getElementById('welcomeMessage');
    const productTableBody = document.getElementById('productTableBody');

    startMicBtn.addEventListener('click', function() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        
        if (!SpeechRecognition) {
            alert("Speech recognition not supported in this browser.");
            return;
        }

        const recognition = new SpeechRecognition();
        recognition.lang = 'en-US';

        recognition.onstart = () => {
            voiceOverlay.style.display = 'flex';
        };

        recognition.onresult = (event) => {
            const text = event.results[0][0].transcript;
            voiceInput.value = text;
            
            // ১. টেবিল এবং বডি শো করা
            productTable.style.display = 'table';
            productTableBody.style.display = 'table-row-group';
            welcomeMessage.style.display = 'none';

            // ২. DataTable এ ফিল্টার অ্যাপ্লাই করা
            if (window.$.fn.DataTable.isDataTable('.zero_config')) {
                const table = $('.zero_config').DataTable();
                table.search(text).draw();
                
                // যদি সার্চে কিছু না পাওয়া যায়
                if (table.rows({filter: 'applied'}).count() === 0) {
                     alert("No product found with this name!");
                }
            }
        };

        recognition.onspeechend = () => {
            recognition.stop();
            voiceOverlay.style.display = 'none';
        };

        recognition.onerror = () => {
            voiceOverlay.style.display = 'none';
        };

        recognition.start();
    });
</script>
@endsection