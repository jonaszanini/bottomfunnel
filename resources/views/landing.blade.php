<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BottomFunnel</title>
<meta name="description" content="BottomFunnel is an internal tool used by Jonas Zanini to manage Google Ads search campaigns and affiliate product research.">
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f2937;background:#f8fafc;line-height:1.6}
.wrap{max-width:720px;margin:0 auto;padding:64px 24px}
h1{font-size:2.5rem;margin:0 0 8px}
.lead{font-size:1.15rem;color:#475569;margin:0 0 32px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:32px}
h2{font-size:1.2rem;margin:0 0 8px}
.btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600}
footer{border-top:1px solid #e2e8f0;margin-top:48px;padding-top:16px;font-size:.9rem;color:#64748b}
footer a{color:#475569;margin-right:16px}
</style>
</head>
<body>
<main class="wrap">
<h1>BottomFunnel</h1>
<p class="lead">Internal campaign management tool.</p>
<section class="card">
<h2>What BottomFunnel does</h2>
<p>BottomFunnel is an internal tool that Jonas Zanini uses to manage his Google Ads search campaigns and his affiliate product research.</p>
<p>It connects to the Google Ads API through Google OAuth to read account data and keyword planning data, such as keyword search volumes, for the accounts he manages.</p>
<p>Access is limited to authorized users.</p>
</section>
<a class="btn" href="{{ url('/login') }}">Login</a>
<footer>
<a href="{{ url('/privacy-policy') }}">Privacy Policy</a>
<a href="{{ url('/terms-of-service') }}">Terms of Service</a>
<span>&copy; {{ date('Y') }} BottomFunnel</span>
</footer>
</main>
</body>
</html>
