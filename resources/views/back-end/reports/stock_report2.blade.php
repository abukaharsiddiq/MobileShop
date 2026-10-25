@extends('back-end.master')

@section('admin-title') {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') {{ $title }} @endsection

@push('admin-styles')
<style>
    .marginTop{ margin-top:33px; }
    /* ভয়েস ওভারলে */
    #voiceOverlay {
        display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.85); z-index: 9999;
        justify-content: center; align-items: center; color: white; flex-direction: column;
    }
    .mic-animation {
        width: 80px; height: 80px; background: #ff4b2b; border-radius: 50%;
        display: flex; justify-content: center; align-items: center;
        margin-bottom: 20px; animation: pulse-red 1.5s infinite;
    }
    @keyframes pulse-red {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0.7); }
        70% { transform: scale(1.1); box-shadow: 0 0 0 20px rgba(255, 75, 43, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0); }
    }
    /* মোবাইল স্ক্যানার প্রিভিউ স্টাইল */
    #scanner-container { display: none; width: 100%; max-width: 500px; margin: 10px auto; border: 2px solid #007bff; }
</style>
@endpush

@section('admin-content')
<div id="voiceOverlay">
    <div class="mic-animation"><i class="fas fa-microphone fa-2x"></i></div>
    <h3>Listening...</h3>
    <p>Try: "Scan Now", "Total Quantity", "Samsung"</p>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card mb-3 no-print">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Search & Filter</h3>
                <div>
                    <button class="btn btn-primary rounded-pill px-3 mr-2" id="startMicBtn">
                        <i class="fas fa-microphone"></i> Voice Search
                    </button>
                    <input type="file" id="barcodeInput" accept="image/*" capture="environment" style="display: none;">
                </div>
            </div>
            <div class="card-body">
                <form action="{{ route('reports.stock') }}" method="GET">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <label>Category</label>
                            <select name="category_id" class="form-control select2">
                                <option value="">All Categories</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label>Product</label>
                            <select name="product_id" class="form-control select2" id="product_id">
                                <option value="">All Products</option>
                            </select>
                        </div>
                        <div class="col-md-3 marginTop">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Filter</button>
                            <a href="{{ route('reports.stock') }}" class="btn btn-secondary"><i class="fa fa-sync"></i> Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="info-box bg-light border shadow-sm">
                    <span class="info-box-icon bg-success"><i class="fa fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Live Total Quantity</span>
                        <span class="info-box-number" id="totalQtyDisplay">0</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border shadow-sm">
                    <span class="info-box-icon bg-info"><i class="fa fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Stock Value</span>
                        <span class="info-box-number">TK {{ number_format($totalStockValue, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="stockTable" class="table table-bordered table-striped">
                        <thead>
                            <tr class="bg-light">
                                <th>SL</th>
                                <th>Product Name</th>
                                <th>Variant</th>
                                <th>Qty</th>
                                <th>Total Value</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody">
                            @foreach($stocks as $key => $stock)
                            <tr>
                                <td>{{ $key + 1 }}</td>
                                <td class="p-name">{{ $stock->product->product_name ?? 'N/A' }}</td>
                                <td>{{ $stock->ram->ram_name ?? '-' }} | {{ $stock->color->color_name ?? '-' }}</td>
                                <td class="font-weight-bold qty-val">{{ $stock->quantity }}</td>
                                <td>{{ $stock->quantity * $stock->average_purchase_price }}</td>
                                <td>
                                    @if($stock->quantity <= 0) <span class="badge badge-danger">Out of Stock</span>
                                    @elseif($stock->isLowStock()) <span class="badge badge-warning">Low Stock</span>
                                    @else <span class="badge badge-success">In Stock</span> @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function() {
        const startMicBtn = document.getElementById('startMicBtn');
        const voiceOverlay = document.getElementById('voiceOverlay');
        const barcodeInput = document.getElementById('barcodeInput');
        const table = $('#stockTable').DataTable();

        function speak(text) {
            const msg = new SpeechSynthesisUtterance(text);
            window.speechSynthesis.speak(msg);
        }

        // ১. অটো কোয়ান্টিটি ক্যালকুলেশন
        function updateLiveQty() {
            let total = 0;
            // শুধুমাত্র ফিল্টারড রো থেকে ডাটা নিবে
            table.rows({ filter: 'applied' }).data().each(function(value, index) {
                // ৩ নম্বর কলামে (index 3) Qty আছে
                let qty = parseFloat(value[3]) || 0;
                total += qty;
            });
            $('#totalQtyDisplay').text(total);
            return total;
        }
        updateLiveQty(); // প্রথমবার রান

        // ২. ভয়েস রিকগনিশন
        const recognition = new (window.SpeechRecognition || window.webkitSpeechRecognition)();
        recognition.lang = 'en-US';

        startMicBtn.addEventListener('click', () => recognition.start());
        recognition.onstart = () => voiceOverlay.style.display = 'flex';
        
        recognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript.toLowerCase();
            voiceOverlay.style.display = 'none';

            // কমান্ড: Scan Now
            if (transcript.includes("scan now") || transcript.includes("open scanner")) {
                speak("Opening camera. Please take a clear photo of the barcode.");
                barcodeInput.click(); // সরাসরি মোবাইল ক্যামেরা ট্রিগার করবে
            }
            // কমান্ড: Total Quantity
            else if (transcript.includes("total quantity") || transcript.includes("stock amount")) {
                const total = updateLiveQty();
                speak("The total quantity currently on screen is " + total);
            }
            // সাধারণ সার্চ
            else {
                table.search(transcript).draw();
                const total = updateLiveQty();
                speak("Showing results for " + transcript + ". Total quantity is " + total);
            }
        };

        recognition.onspeechend = () => voiceOverlay.style.display = 'none';

        // ৩. বারকোড ফাইল ইনপুট হ্যান্ডলার (ছবি তোলার পর)
        barcodeInput.addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                speak("Processing barcode. Searching in inventory.");
                // এখানে আপনি আপনার বারকোড ডিকোড করার লজিক বা সরাসরি সার্চ দিতে পারেন
                // আপাতত এটি একটি ডামি অ্যাকশন হিসেবে একটি মেসেজ দিবে
                alert("Barcode captured! System is searching for the product...");
            }
        });

        // DataTable সার্চ করলে কোয়ান্টিটি অটো আপডেট হবে
        table.on('draw', function() {
            updateLiveQty();
        });
    });
</script>
@endpush