<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ ($setting->name ?? config('app.name')) . ' | Log in' }}</title>
  @include('back-end.includes.theme-head')

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;600&display=swap">
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="{{ asset('/') }}back-end/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="{{ asset('/') }}back-end/css/premium.css?v={{ filemtime(public_path('back-end/css/premium.css')) }}">
</head>
<body>
<div class="auth-shell">
  {{-- bam dike: dokaner naam ar ki kora jay --}}
  <section class="auth-hero">
    <div class="brand-mark">
      <span class="logo-chip"><i class="fas fa-mobile-alt"></i></span>
      <span>{{ $setting->name ?? config('app.name') }}</span>
    </div>
    <div>
      <h1>Run your mobile shop from one place.</h1>
      <p>Sales, stock and IMEI, installments, warranty and the books - always in step.</p>
      <ul class="auth-points">
        <li><i class="fas fa-barcode"></i> Every phone tracked by IMEI, from purchase to warranty</li>
        <li><i class="fas fa-cash-register"></i> Fast POS with split payments and daily cash closing</li>
        <li><i class="fas fa-chart-line"></i> Live profit, ledgers and financial statements</li>
      </ul>
    </div>
    <small class="text-white-50">&copy; {{ date('Y') }} {{ $setting->name ?? config('app.name') }}</small>
  </section>

  <section class="auth-panel">
    <button type="button" class="btn btn-secondary topbar-icon-btn theme-toggle" data-theme-toggle title="Switch theme"><i class="fas fa-moon"></i></button>
    <div class="auth-card">
      <div class="text-center mb-4">
        @if(isset($setting) && $setting->logo)
          <img src="{{ asset('back-end/setting/'.$setting->logo) }}" alt="Logo" class="auth-logo-img">
        @endif
      </div>
      <div class="card">
        <div class="card-body">
          <h2>Welcome back</h2>
          <p class="text-muted mb-4">Sign in to continue to your dashboard.</p>

          {{-- bhul password, inactive account, beshi chesta (rate limit) ba password reset er message --}}
          @if ($errors->any())
            <div class="alert alert-danger py-2"><i class="fas fa-exclamation-circle mr-1"></i> {{ $errors->first() }}</div>
          @endif
          @if (session('status'))
            <div class="alert alert-success py-2">{{ session('status') }}</div>
          @endif

          <form action="{{ route('login') }}" method="post" onsubmit="this.querySelector('button[type=submit]').disabled = true">
            @csrf
            <div class="form-group">
              <label for="email">Email</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com" value="{{ old('email') }}" autocomplete="username" required autofocus>
              </div>
            </div>
            <div class="form-group mb-4">
              <label for="password">Password</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-lock"></i></span></div>
                <input type="password" id="password" name="password" class="form-control" placeholder="Your password" autocomplete="current-password" required>
                <div class="input-group-append">
                  <button type="button" class="btn btn-outline-secondary" onclick="const p = document.getElementById('password'); p.type = p.type === 'password' ? 'text' : 'password'; this.firstElementChild.classList.toggle('fa-eye-slash');" title="Show password">
                    <i class="fas fa-eye"></i>
                  </button>
                </div>
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Sign in <i class="fas fa-arrow-right ml-1"></i></button>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<script src="{{ asset('/') }}back-end/plugins/jquery/jquery.min.js"></script>
<script src="{{ asset('/') }}back-end/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
