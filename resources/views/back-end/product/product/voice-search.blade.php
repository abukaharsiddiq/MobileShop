@extends('back-end.master')

@section('admin-content')
<style>
    #voiceOverlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        color: white;
        text-align: center;
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
</style>

<div id="voiceOverlay">
    <div>
        <div class="mic-animation" style="margin: 0 auto 20px;">
            <svg width="50" height="50" viewBox="0 0 16 16" fill="white" xmlns="http://www.w3.org/2000/svg">
                <path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>
                <path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/>
            </svg>
        </div>
        <h3>Listening... Please Speak</h3>
        <p>আমি শুনছি, আপনার প্রোডাক্টের নাম বলুন</p>
    </div>
</div>

<div class="container-fluid">
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-3">Quick Product Search</h5>
            
            <div style="max-width: 400px; display: flex; border: 2px solid #007bff; border-radius: 30px; overflow: hidden; background: #fff;">
                <input type="text" id="voiceInput" placeholder="এখানে নাম আসবে..." style="border: none; padding: 10px 20px; flex: 1; outline: none;">
                <button type="button" id="startMic" style="background: #007bff; border: none; padding: 10px 20px; cursor: pointer;">
                    <svg width="20" height="20" viewBox="0 0 16 16" fill="white" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>
                        <path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="card-body">
            <table class="table table-bordered zero_config1">
                <thead>
                    <tr><th>#</th><th>Product Name</th><th>Model</th><th>Category</th></tr>
                </thead>
                <tbody>
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

    startMicBtn.addEventListener('click', function() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        
        if (!SpeechRecognition) {
            alert("Your browser does not support voice search. Please use Chrome.");
            return;
        }

        const recognition = new SpeechRecognition();
        // recognition.lang = 'bn-BD';
        recognition.lang = 'en-US';

        recognition.onstart = () => {
            voiceOverlay.style.display = 'flex'; // ওভারলে দেখাবে
        };

        recognition.onresult = (event) => {
            const text = event.results[0][0].transcript;
            voiceInput.value = text;
            
            if (window.$.fn.DataTable.isDataTable('.zero_config')) {
                $('.zero_config').DataTable().search(text).draw();
            }
        };

        recognition.onspeechend = () => {
            recognition.stop();
            voiceOverlay.style.display = 'none';
        };

        recognition.onerror = () => {
            voiceOverlay.style.display = 'none';
            alert("Error occurred. Please try again.");
        };

        recognition.start();
    });
</script>
@endsection