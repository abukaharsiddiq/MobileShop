const cacheName = 'pos-v1';
const staticAssets = [
    '/',
    '/sale', 
    '/sale/create',
    // নিশ্চিত করুন নিচের ফাইলগুলোর পাথ একদম ঠিক আছে
    // ডিজাইন ঠিক রাখতে আপনার সব CSS/JS ফাইল এখানে দিন
    'https://unpkg.com/html5-qrcode'
];

// ইনস্টল এবং ক্যাশ করা
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(cacheName).then(cache => {
            return cache.addAll(staticAssets);
        })
    );
    self.skipWaiting();
});

// অফলাইনে পেজ লোড করার উন্নত লজিক
self.addEventListener('fetch', e => {
    // POST রিকোয়েস্ট (যেমন ডাটা সেভ করা) ক্যাশ থেকে হয় না, তাই ওগুলো বাদ
    if (e.request.method !== 'GET') return;

    e.respondWith(
        fetch(e.request).catch(() => {
            return caches.match(e.request).then(response => {
                // যদি নির্দিষ্ট ইউআরএল না পায়, তবে মেইন সেল পেজটি রিটার্ন করবে
                return response || caches.match('/sale/create');
            });
        })
    );
});