{{-- Error page: kono technical tottho (exception, SQL, path) dekhano hoy na. Database chara o chole. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') | @yield('title')</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f4f6f9; color: #343a40; }
        .box { max-width: 460px; padding: 40px 32px; margin: 16px; background: #fff; border-radius: 8px;
               box-shadow: 0 1px 3px rgba(0,0,0,.12); text-align: center; }
        .code { font-size: 56px; font-weight: 700; color: #6c757d; margin: 0; }
        h1 { font-size: 20px; margin: 8px 0 12px; }
        p { color: #6c757d; line-height: 1.5; margin: 0 0 24px; }
        a { display: inline-block; padding: 8px 18px; border-radius: 4px; background: #007bff; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/') }}">Go to Dashboard</a>
    </div>
</body>
</html>
