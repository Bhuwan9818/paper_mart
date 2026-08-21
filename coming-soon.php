<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>paperKart — Launching Soon</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'DM Sans',sans-serif;
    min-height:100vh;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#8b241d 0%,#6b1a14 100%);
    color:#fff;
    padding:24px;
    text-align:center;
  }
  .wrap{max-width:560px}
  .diamond{font-size:32px;margin-bottom:18px;color:#f0c060}
  h1{
    font-family:'Playfair Display',serif;
    font-size:clamp(30px,5vw,44px);
    font-weight:800;
    margin-bottom:14px;
    letter-spacing:-.01em;
  }
  .sub{
    font-size:clamp(15px,2vw,17px);
    color:rgba(255,255,255,.88);
    line-height:1.6;
    margin-bottom:32px;
  }
  .divider{
    width:56px;height:3px;background:#f0c060;
    margin:0 auto 32px;border-radius:2px;
  }
  .badge{
    display:inline-flex;align-items:center;gap:8px;
    background:rgba(255,255,255,.1);
    border:1px solid rgba(255,255,255,.18);
    border-radius:100px;
    padding:9px 20px;
    font-size:13.5px;font-weight:600;
    color:#fff;
  }
  .badge .dot{width:7px;height:7px;border-radius:50%;background:#f0c060;animation:pulse 1.8s ease-in-out infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
  footer{margin-top:40px;font-size:12px;color:rgba(255,255,255,.5)}
</style>
</head>
<body>
  <div class="wrap">
    <div class="diamond">&#9670;</div>
    <h1>Something Great Is Coming</h1>
    <div class="divider"></div>
    <p class="sub">paperKart — a new B2B marketplace connecting paper and packaging buyers directly with verified mills and vendors — is currently being built. We'll be live shortly.</p>
    <div class="badge"><span class="dot"></span> Launching Soon</div>
    <footer>&copy; <?= date('Y') ?> paperKart. All rights reserved.</footer>
  </div>
</body>
</html>
