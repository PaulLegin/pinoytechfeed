<?php
// build-site.php — generates /p/*.html pages with OG + image proxy + local thumbnail support

$SITE = 'https://pinoytechfeed.pages.dev'; // change only if you use a different domain
$DATA = json_decode(file_get_contents('feed.json'), true);
$OUT = __DIR__ . '/p';
if (!is_dir($OUT)) mkdir($OUT, 0777, true);

// Image proxy for broken or hotlinked thumbnails
function proxy_img($url, $w=1200, $h=630) {
  if (!$url) return '';
  $u = parse_url($url);
  if (empty($u['host'])) return $url;
  $hostPath = $u['host'] . ($u['path'] ?? '') . (isset($u['query']) ? '?'.$u['query'] : '');
  return 'https://images.weserv.nl/?url=' . rawurlencode($hostPath) . "&w={$w}&h={$h}&fit=cover&we";
}

foreach ($DATA as $p) {
  $slug = $p['slug'];
  $title = htmlspecialchars($p['title'], ENT_QUOTES);
  $desc = htmlspecialchars($p['desc'] ?: $p['title'], ENT_QUOTES);
  $img  = $p['img'] ?: "$SITE/assets/ptf-cover.png";
  $cat  = htmlspecialchars($p['category']);
  $src  = htmlspecialchars($p['source']);
  $date = htmlspecialchars($p['ts']);
  $url  = "$SITE/p/$slug.html";

  // Download local OG image (to avoid hotlinking / Facebook cache problems)
  $ogFile = "$OUT/og-$slug.jpg";
  if (!file_exists($ogFile) && !empty($p['img'])) {
    $imgData = @file_get_contents($p['img']);
    if ($imgData) @file_put_contents($ogFile, $imgData);
  }
  $ogImg = file_exists($ogFile)
    ? "$SITE/p/og-$slug.jpg"
    : proxy_img($p['img']); // fallback proxy

  $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>$title · PinoyTechFeed</title>
<meta name="description" content="$desc" />

<!-- Canonical and OG URL point to your page (not the original site) -->
<link rel="canonical" href="$url" />
<meta property="og:url" content="$url" />
<meta property="og:type" content="article" />
<meta property="og:site_name" content="PinoyTechFeed" />
<meta property="og:title" content="$title" />
<meta property="og:description" content="$desc" />
<meta property="og:image" content="$ogImg" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />
<meta property="og:image:alt" content="$title" />
<meta property="og:locale" content="en_PH" />

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="$title" />
<meta name="twitter:description" content="$desc" />
<meta name="twitter:image" content="$ogImg" />

<!-- Optional: note the original source but keep OG canonical on your site -->
<meta name="original-source" content="{$p['target']}" />

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
    <img src="$ogImg" alt="$title" />
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