<?php

namespace App\Http\Controllers;

use App\Models\TailorProduct;
use App\Models\TailorMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VirtualTryOnController extends Controller {

  public function showTryOn($id) {
        $product = TailorProduct::findOrFail($id);
        // সব একটিভ প্রোডাক্ট নিয়ে আসা যাতে সাইডবারে অন্য ডিজাইন চয়েস করা যায়
        $all_products = TailorProduct::where('status', 1)->get();
        
        return view('back-end.tailor.tryon.show', compact('product', 'all_products'));
    }

    /**
     * এআই প্রসেস করার ফাইনাল মেথড
     */
    public function processTryOn123(Request $request) {
        try {
            // ১. ভ্যালিডেশন এবং ডাটা সেটআপ
            $token = config('services.replicate.token');
            $product = TailorProduct::findOrFail($request->product_id);

            if (!$request->hasFile('customer_image')) {
                return response()->json(['success' => false, 'message' => 'Please upload your photo.'], 400);
            }

            // ২. কাস্টমার ইমেজ সেভ করা
            $customerImg = $request->file('customer_image');
            $customerName = time() . '_user.jpg';
            $customerImg->move(public_path('temp'), $customerName);

            // ৩. পাবলিক ইউআরএল তৈরি (আপনার ngrok লিঙ্ক)
            // নোট: ngrok বন্ধ করে আবার চালু করলে নিচের লিঙ্কটি অবশ্যই পরিবর্তন করবেন
            $baseUrl = "https://subaggregately-overfraught-belkis.ngrok-free.dev";
            
            $customerUrl = $baseUrl . "/temp/" . $customerName;
            $garmentUrl = $baseUrl . "/" . $product->garment_image_transparent;

            // ৪. মেজারমেন্ট ডাটা সংগ্রহ (যদি থাকে)
            $measurement = TailorMeasurement::where('user_id', auth()->id())->first();
            $garmentDescription = $product->dress_name . ($measurement ? " with chest: {$measurement->body_chest}, waist: {$measurement->waist}" : " custom outfit");

            // ৫. Replicate API কল (ngrok স্কিপ হেডারসহ)
            $response = Http::withToken($token)
                ->withHeaders([
                    'Prefer' => 'wait',
                    'ngrok-skip-browser-warning' => 'true' // এটি এআই-কে আপনার ইমেজ এক্সেস করতে দিবে
                ])
                ->timeout(120) // এআই ইমেজ জেনারেট করতে সময় নিতে পারে
                ->post('https://api.replicate.com/v1/predictions', [
                    'version' => '0513734a452173b8173e907e3a59d19a36266e55b48528559432bd21c7d7e985',
                    'input' => [
                        'human_img' => $customerUrl,
                        'garm_img' => $garmentUrl,
                        'garment_des' => $garmentDescription,
                        'category' => 'upper_body',
                        'crop' => false,
                        'steps' => 30
                    ]
                ]);

            // ৬. রেসপন্স চেক করা
            if ($response->successful()) {
                $res = $response->json();
                
                // যদি সরাসরি 'output' চলে আসে (Prefer: wait এর কারণে)
                $finalImage = null;
                if (isset($res['output'])) {
                    $finalImage = is_array($res['output']) ? $res['output'][0] : $res['output'];
                }

                // যদি আউটপুট ইউআরএল পাওয়া যায়
                if ($finalImage) {
                    return response()->json([
                        'success' => true,
                        'image' => $finalImage
                    ]);
                }
            }

            // যদি এরর হয়, তবে বিস্তারিত লগ করা
            Log::error('Replicate API Error Response:', $response->json());
            
            return response()->json([
                'success' => false, 
                'message' => 'AI process failed. Check your connection or image format.',
                'debug' => $response->json() // এটি দিয়ে আপনি বুঝতে পারবেন কেন ফেইল হয়েছে
            ], 500);

        } catch (\Exception $e) {
            Log::error('TryOn Exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => errorMessage($e)], 500);
        }
    }


    public function processTryOn11(Request $request) {
    try {
        $apiKey = config('services.fal.key'); // 'Key key-id:key-secret' ফরম্যাটে থাকে
        $product = TailorProduct::findOrFail($request->product_id);

        $customerImg = $request->file('customer_image');
        $customerName = time() . '_user.jpg';
        $customerImg->move(public_path('temp'), $customerName);

        // Fal.ai এর জন্য ngrok লিঙ্ক লাগবে
        $baseUrl = "https://subaggregately-overfraught-belkis.ngrok-free.dev";
        $customerUrl = $baseUrl . "/temp/" . $customerName;
        $garmentUrl = $baseUrl . "/" . $product->garment_image_transparent;

        $response = Http::withHeaders([
            'Authorization' => 'Key ' . $apiKey,
            'Content-Type' => 'application/json'
        ])->post('https://fal.run/fal-ai/idm-vton', [
            'human_image_url' => $customerUrl,
            'garment_image_url' => $garmentUrl,
            'description' => "a stylish " . $product->dress_name
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return response()->json([
                'success' => true,
                'image' => $data['image']['url']
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Fal.ai error', 'debug' => $response->json()]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => errorMessage($e)]);
    }
}

public function processTryOn123456(Request $request) {
    try {
        $product = TailorProduct::findOrFail($request->product_id);
        
        // ছবি সেভ করা
        $customerImg = $request->file('customer_image');
        $customerName = time() . '_user.jpg';
        $customerImg->move(public_path('temp'), $customerName);

        // ছবিগুলোকে Base64 এ কনভার্ট করা
        $customerBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents(public_path('temp/' . $customerName)));
        $garmentBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents(public_path($product->garment_image_transparent)));

        // এই এন্ডপয়েন্টটি একটি ফ্রি ডেমো স্পেস থেকে নেওয়া, এটি ট্রাই করুন
        // যদি এটি কাজ না করে, আমি আপনাকে আরেকটি দিচ্ছি
        $apiUrl = "https://yisol-idm-vton.hf.space/run/predict";

        $response = Http::timeout(120)->post($apiUrl, [
            'data' => [
                ["background" => $customerBase64, "layers" => [], "composite" => null],
                $garmentBase64,
                "a beautiful " . $product->dress_name,
                true,  // is_checked
                true,  // is_checked_crop
                20,    // denoise_steps (কমিয়ে ২০ দেওয়া হয়েছে দ্রুত রেজাল্টের জন্য)
                42     // seed
            ]
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['data'][0]['url'])) {
                return response()->json([
                    'success' => true,
                    'image' => $data['data'][0]['url']
                ]);
            }
        }

        // যদি উপরেরটা ব্যস্ত থাকে, তবে আমরা সরাসরি Replicate-এর একটি ফ্রি ট্রায়াল লিঙ্ক খোঁজার চেষ্টা করব
        return response()->json([
            'success' => false, 
            'message' => 'All free servers are busy. Please try again in a few seconds.',
            'debug' => $response->status()
        ], 503);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => errorMessage($e)], 500);
    }
}

public function processTryOn(Request $request) {
    try {
        $product = TailorProduct::findOrFail($request->product_id);
        
        // স্ল্যাশ ঠিক করা হয়েছে
        $pythonApiUrl = "https://294c43553779358d0b.gradio.live/queue/join"; 

        $customerImg = $request->file('customer_image');
        $customerBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($customerImg->getPathname()));
        $garmentBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents(public_path($product->garment_image_transparent)));

        // Gradio Queue এর জন্য সঠিক ডাটা ফরম্যাট
        $payload = [
            'data' => [
                ["background" => $customerBase64, "layers" => [], "composite" => null], // Person image object
                $garmentBase64,       // Garment image
                "fashionable dress",  // Description
                true,                 // is_checked
                false,                // is_checked_crop
                30,                   // denoise_steps
                42                    // seed
            ],
            'fn_index' => 0,
            'trigger_id' => 6, // Gradio-র এই মডেলের জন্য ইন্টারনাল আইডি
            'session_hash' => substr(md5(mt_rand()), 0, 11)
        ];

        $response = Http::timeout(30)->post($pythonApiUrl, $payload);

        if ($response->successful()) {
            return response()->json([
                'success' => true,
                'message' => 'Request added to queue!',
                'event_id' => $response->json()['event_id'] ?? null
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Error: ' . $response->body()]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => errorMessage($e)]);
    }
}
  
}
