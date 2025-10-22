<?php
// build-site.php
// Generate article pages (/p/*.html) with Open Graph tags for Facebook thumbnail

$SITE = 'https://pinoytechfeed.pages.dev'; // change if needed
$DATA = json_decode(file_get_contents('feed.json'), true);
$OUT = __DIR__ . '/p';

if (!is_dir($OUT)) mkdir($OUT, 0777, true);

foreach ($DATA as $p) {
  $slug = $p['slug'];
  $title = htmlspecialchars($p['title'], ENT_QUOTES);
  $desc = htmlspecialchars($p['desc'] ?: $p['title'], ENT_QUOTES);
  $img  = $p['img'] ?: "$SITE/assets/ptf-cover.png";
  $cat  = htmlspecialchars($p['category']);
  $src  = htmlspecialchars($p['source']);
  $date = htmlspecialchars($p['ts']);
  $url  = "$SITE/p/$slug.html";

  $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>$title · PinoyTechFeed</title>
<meta name="description" content="$desc">

<!-- Open Graph -->
<meta property="og:type" content="article">
<meta property="og:site_name" content="PinoyTechFeed">
<meta property="og:title" content="$title">
<meta property="og:description" content="$desc">
<meta property="og:url" content="$url">
<meta property="og:image" content="$img">
<meta property="og:image:alt" content="$title">
<meta property="og:locale" content="en_PH">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="$title">
<meta name="twitter:description" content="$desc">
<meta name="twitter:image" content="$img">

<style>
  body {font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,sans-serif;margin:0;padding:0;background:#0b1220;color:#e2e8f0;}
  .wrap {max-width:800px;margin:40px auto;padding:0 16px;}
  img {max-width:100%;border-radius:10px;}
  a {color:#22c55e;text-decoration:none;}
  .meta {color:#9db7d8;font-size:14px;margin-bottom:10px;}
</style>
</head>
<body>
  <div class="wrap">
    <h1>$title</h1>
    <div class="meta">📅 $date · 🏷️ $cat · 🔗 $src</div>
    <img src="$img" alt="$title">
    <p style="margin-top:16px;">$desc</p>
    <p><a href="{$p['target']}" target="_blank" rel="noopener">Read full article from $src →</a></p>
    <p><a href="$SITE" style="color:#94a3b8;">← Back to PinoyTechFeed</a></p>
  </div>
</body>
</html>
HTML;

  file_put_contents("$OUT/$slug.html", $html);
}

echo "✅ Built " . count($DATA) . " pages in /p\n";
?>