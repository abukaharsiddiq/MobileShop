@extends('back-end.master')

@section('admin-title') {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') {{ $title }} @endsection

@push('admin-styles')
<style>
    .marginTop{ margin-top:33px; }
    /* ভয়েস ওভারলে স্টাইল */
    #voiceOverlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.85);
        z-index: 9999;
        justify-content: center; align-items: center;
        color: white; text-align: center; flex-direction: column;
    }
    .mic-animation {
        width: 80px; height: 80px;
        background: #ff4b2b; border-radius: 50%;
        display: flex; justify-content: center; align-items: center;
        margin-bottom: 20px; animation: pulse-red 1.5s infinite;
    }
    @keyframes pulse-red {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0.7); }
        70% { transform: scale(1.1); box-shadow: 0 0 0 20px rgba(255, 75, 43, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 75, 43, 0); }
    }
</style>
@endpush

@section('admin-content')
<div id="voiceOverlay">
    <div class="mic-animation"><i class="fas fa-microphone fa-2x"></i></div>
    <h3>Listening...</h3>
    <p>Try: "Samsung", "Total Quantity", "Low Stock"</p>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card mb-3 no-print">
            <div class="card-header d-flex justify-content-between">
                <h3 class="card-title">Search & Filter</h3>
                <button class="btn btn-outline-primary btn-sm rounded-pill px-3" id="startMicBtn">
                    <i class="fas fa-microphone"></i> Voice Search
                </button>
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
                        
                        <div class="col-md-2 mb-2">
                            <label>RAM</label>
                            <select name="ram_id" class="form-control select2">
                                <option value="">All</option>
                                @foreach($rams as $ram)
                                    <option value="{{ $ram->id }}" {{ request('ram_id') == $ram->id ? 'selected' : '' }}>{{ $ram->ram_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label>ROM</label>
                            <select name="rom_id" class="form-control select2">
                                <option value="">All</option>
                                @foreach($roms as $rom)
                                    <option value="{{ $rom->id }}" {{ request('rom_id') == $rom->id ? 'selected' : '' }}>{{ $rom->rom_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label>Color</label>
                            <select name="color_id" class="form-control select2">
                                <option value="">All</option>
                                @foreach($colors as $color)
                                    <option value="{{ $color->id }}" {{ request('color_id') == $color->id ? 'selected' : '' }}>{{ $color->color_name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3 marginTop">
                            <button type="submit" class="btn btn-md btn-primary"><i class="fa fa-search"></i> Filter</button>
                            <a href="{{ route('reports.stock') }}" class="btn btn-md btn-secondary"><i class="fa fa-sync"></i> Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-info"><i class="fa fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Filtered Stock Value</span>
                        <span class="info-box-number">TK {{ number_format($totalStockValue, 2) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-success"><i class="fa fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Quantity</span>
                        <span class="info-box-number" id="totalQtyDisplay">Calculating...</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Current Stock Report</h3>
                <button onclick="window.print()" class="btn btn-sm btn-primary float-right no-print">
                    <i class="fa fa-print"></i> Print Report
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="example1" class="table table-bordered table-striped">
                        <thead>
                            <tr class="bg-light text-center">
                                <th>SL</th>
                                <th>Product Name</th>
                                <th>Variant (R/R/C)</th>
                                <th>Qty</th>
                                <th>Avg. Price</th>
                                <th>Total Value</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody">
                            @foreach($stocks as $key => $stock)
                            @php $totalValue = $stock->quantity * $stock->average_purchase_price; @endphp
                            <tr>
                                <td class="text-center">{{ $key + 1 }}</td>
                                <td class="product-name">{{ $stock->product->product_name ?? 'N/A' }}</td>
                                <td class="text-center">
                                    {{ $stock->ram->ram_name ?? '-' }} | 
                                    {{ $stock->rom->rom_name ?? '-' }} | 
                                    {{ $stock->color->color_name ?? '-' }}
                                </td>
                                <td class="text-center font-weight-bold qty-val">
                                    {{ $stock->quantity }}
                                </td>
                                <td class="text-right">{{ intval($stock->average_purchase_price) }}</td>
                                <td class="text-right font-weight-bold">{{ $totalValue }}</td>
                                <td class="text-center status-label">
                                    @if($stock->quantity <= 0)
                                        <span class="badge badge-danger">Out of Stock</span>
                                    @elseif($stock->quantity <= 5)
                                        <span class="badge badge-warning">Low Stock</span>
                                    @else
                                        <span class="badge badge-success">In Stock</span>
                                    @endif
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
    // ভয়েস এবং কোয়ান্টিটি লজিক
    $(document).ready(function() {
        const startMicBtn = document.getElementById('startMicBtn');
        const voiceOverlay = document.getElementById('voiceOverlay');
        
        function speakResult(text) {
            const speech = new SpeechSynthesisUtterance(text);
            window.speechSynthesis.speak(speech);
        }

        // ১. কোয়ান্টিটি ক্যালকুলেশন ফাংশন
        function calculateTotalQty() {
            let total = 0;
            // শুধুমাত্র টেবিলের দৃশ্যমান (visible) রো গুলোর কোয়ান্টিটি যোগ করবে
            $('#stockTableBody tr:visible').each(function() {
                let qty = parseFloat($(this).find('.qty-val').text()) || 0;
                total += qty;
            });
            $('#totalQtyDisplay').text(total);
            return total;
        }

        // পেজ লোড হলে একবার রান হবে
        calculateTotalQty();

        // ২. ভয়েস রিকগনিশন সেটআপ
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (SpeechRecognition) {
            const recognition = new SpeechRecognition();
            recognition.lang = 'en-US';

            startMicBtn.addEventListener('click', () => recognition.start());
            recognition.onstart = () => { voiceOverlay.style.display = 'flex'; };

            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript.toLowerCase();
                voiceOverlay.style.display = 'none';

                // ক. কোয়ান্টিটি জানার কমান্ড
                if (transcript.includes("total quantity") || transcript.includes("stock amount")) {
                    const total = calculateTotalQty();
                    speakResult("The total quantity in current view is " + total);
                } 
                // খ. লো স্টক ফিল্টার কমান্ড
                else if (transcript.includes("low stock")) {
                    $('#example1').DataTable().search("Low Stock").draw();
                    const total = calculateTotalQty();
                    speakResult("Showing low stock items. Total quantity is " + total);
                }
                // গ. সাধারণ সার্চ (প্রোডাক্টের নাম)
                else {
                    $('#example1').DataTable().search(transcript).draw();
                    const total = calculateTotalQty();
                    speakResult("Showing results for " + transcript + ". Total quantity is " + total);
                }
            };

            recognition.onspeechend = () => { voiceOverlay.style.display = 'none'; };
        }

        // DataTable সার্চের সময় কোয়ান্টিটি আপডেট করা
        $('#example1').on('draw.dt', function() {
            calculateTotalQty();
        });

        // ক্যাটাগরি অনুযায়ী প্রোডাক্ট লোড (আপনার আগের কোড)
        $('select[name="category_id"]').on('change', function() {
            var category_id = $(this).val();
            var product_select = $('select[name="product_id"]');
            product_select.empty().append('<option value="">All Products</option>');

            if (category_id) {
                $.ajax({
                    url: "{{ url('/get/products-by-category') }}/" + category_id,
                    type: "GET",
                    dataType: "json",
                    success: function(data) {
                        $.each(data, function(key, value) {
                            product_select.append('<option value="' + value.id + '">' + value.product_name + '</option>');
                        });
                        product_select.trigger('change');
                    }
                });
            }
        });
    });
</script>
@endpush