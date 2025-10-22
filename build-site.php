<?php
/**
 * build-site.php — Builds per-article landing pages in /p/ from feed.xml
 * Goal: Nice Facebook/X previews with our own URL + thumbnail
 */

date_default_timezone_set('Asia/Manila');

/* ----------------------------------------------------------
   ORIGIN DETECTION (same spirit as your build-feed.php)
-----------------------------------------------------------*/
function arg_val($argv, $key) {
  foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$key=")) return substr($a, strlen($key) + 3);
  return null;
}
function normalize_origin($s) {
  if (!$s) return '';
  $s = trim($s);
  if (!preg_match('~^https?://~i', $s)) $s = 'https://' . $s;
  return rtrim($s, '/');
}
function detect_origin() {
  global $argv;
  if (isset($argv)) {
    $cli = arg_val($argv, 'origin');
    if ($cli) return normalize_origin($cli);
  }
  $candidates = [
    'SITE_ORIGIN',
    'CF_PAGES_URL','PAGES_URL',
    'NETLIFY_SITE_URL',
    'VERCEL_URL',
    'RENDER_EXTERNAL_URL',
    'RAILWAY_PUBLIC_DOMAIN',
    'GITHUB_PAGES_URL','PUBLIC_URL',
  ];
  foreach ($candidates as $k) {
    $v = getenv($k);
    if ($v) return normalize_origin($v);
  }
  return ''; // ok lang; gagamit ng fallback host sa canonical kung wala
}
$ORIGIN = detect_origin();
$FALLBACK_ORIGIN = 'https://pinoytechfeed.pages.dev'; // adjust mo lang kung lilipat ka ng domain
$BASE = $ORIGIN ?: $FALLBACK_ORIGIN;

/* ----------------------------------------------------------
   UTILITIES
-----------------------------------------------------------*/
function shorten($s, $n=220) {
  $s = trim(preg_replace('/\s+/', ' ', $s ?: ''));
  if (mb_strlen($s) <= $n) return $s;
  return rtrim(mb_substr($s, 0, $n-1)).'…';
}
function slugify($s) {
  $s = mb_strtolower($s ?? '');
  $s = preg_replace('~[^\pL\d]+~u', '-', $s);
  $s = trim($s, '-');
  if ($s==='') $s = 'post';
  return $s;
}
function img_proxy($url) {
  if (!$url) return '';
  // Make sure it's absolute and https-capable for Open Graph
  // Use wsrv.nl proxy (fast, widely used). We also set size 1200x630.
  // Strip scheme for wsrv to fetch via https automatically.
  $u = trim($url);
  if (preg_match('~^https?://~i', $u)) {
    $u2 = preg_replace('~^https?://~i', '', $u);
    return 'https://wsrv.nl/?url='.rawurlencode($u2).'&w=1200&h=630&fit=cover&we';
  }
  // If somehow relative, just return fallback empty
  return '';
}

/* ----------------------------------------------------------
   READ FEED
-----------------------------------------------------------*/
$feed = @file_get_contents(__DIR__.'/feed.xml');
if ($feed===false) {
  fwrite(STDERR, "❌ Cannot read feed.xml\n");
  exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($feed);
if (!$xml || !isset($xml->channel->item)) {
  fwrite(STDERR, "❌ Invalid feed.xml\n");
  exit(1);
}

/* ----------------------------------------------------------
   PARSE ITEMS (title/link/desc/date/image/category/source)
-----------------------------------------------------------*/
$items = [];
$ns = $xml->getNamespaces(true);

foreach ($xml->channel->item as $it) {
  $title = trim((string)$it->title);
  $link  = trim((string)$it->link);
  $desc  = trim((string)$it->description);
  $date  = trim((string)$it->pubDate);
  $cat   = trim((string)$it->category);
  $src   = trim((string)$it->source);

  // image
  $img = '';
  // enclosure
  if (isset($it->enclosure)) {
    $attrs = $it->enclosure->attributes();
    if (!empty($attrs['url'])) $img = (string)$attrs['url'];
  }
  // media:content
  if (!$img && !empty($ns['media'])) {
    $media = $it->children($ns['media']);
    if (isset($media->content)) {
      $attrs = $media->content->attributes();
      if (!empty($attrs['url'])) $img = (string)$attrs['url'];
    }
  }
  // fallback: sniff first <img> (rarely needed)
  if (!$img && $desc && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $desc, $m)) {
    $img = $m[1];
  }

  if (!$title || !$link) continue;

  $items[] = [
    'title' => $title,
    'link'  => $link,
    'desc'  => $desc,
    'date'  => $date,
    'img'   => $img,
    'cat'   => $cat,
    'src'   => $src,
  ];
}

/* ----------------------------------------------------------
   WRITE PAGES
-----------------------------------------------------------*/
$dir = __DIR__ . '/p';
if (!is_dir($dir)) mkdir($dir, 0777, true);

$made = 0;

foreach ($items as $it) {
  $slug = slugify($it['title']);
  // append small hash para unique kahit magkapareho ang title
  $hash = substr(md5($it['link']), 0, 6);
  $file = "{$dir}/{$slug}-{$hash}.html";
  $pageUrl = "{$BASE}/p/{$slug}-{$hash}.html";

  $desc = shorten($it['desc'] ?: $it['title'], 220);
  $img  = img_proxy($it['img']); // 1200x630
  $dateIso = gmdate('c', strtotime($it['date'] ?: 'now'));

  $titleEsc = htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8');
  $descEsc  = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
  $srcEsc   = htmlspecialchars($it['src'] ?: '', ENT_QUOTES, 'UTF-8');
  $catEsc   = htmlspecialchars($it['cat'] ?: '', ENT_QUOTES, 'UTF-8');
  $linkEsc  = htmlspecialchars($it['link'], ENT_QUOTES, 'UTF-8');

  // HTML page with full OG tags (width/height included)
  $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />

<title>{$titleEsc} · PinoyTechFeed</title>
<link rel="canonical" href="{$pageUrl}" />

<meta property="og:type" content="article" />
<meta property="og:url" content="{$pageUrl}" />
<meta property="og:title" content="{$titleEsc}" />
<meta property="og:description" content="{$descEsc}" />
HTML;

  if ($img) {
    $html .= "\n<meta property=\"og:image\" content=\"{$img}\" />\n";
    $html .= "<meta property=\"og:image:width\" content=\"1200\" />\n";
    $html .= "<meta property=\"og:image:height\" content=\"630\" />\n";
  }

  // Twitter card
  $html .= <<<HTML
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="{$titleEsc}" />
<meta name="twitter:description" content="{$descEsc}" />
HTML;

  if ($img) {
    $html .= "\n<meta name=\"twitter:image\" content=\"{$img}\" />\n";
  }

  // Basic styles + body
  $html .= <<<HTML
<style>
  :root{--bg:#0b1220;--fg:#e2e8f0;--muted:#94a3b8;--card:#0f172a;--brd:#213048;--accent:#22c55e}
  body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue","Noto Sans",Arial}
  .wrap{max-width:880px;margin:24px auto;padding:0 16px}
  a{color:#8ab4ff}
  .card{background:var(--card);border:1px solid var(--brd);border-radius:12px;padding:16px}
  .thumb{width:100%;max-height:420px;object-fit:cover;border-radius:8px;border:1px solid var(--brd);background:#0b1220}
  .meta{color:var(--muted);font-size:14px;margin:8px 0}
  .btn{display:inline-block;margin-top:12px;padding:10px 14px;border:1px solid var(--brd);border-radius:8px;text-decoration:none;color:var(--fg);background:#0b1324}
  .btn:hover{border-color:#375184}
</style>
</head>
<body>
  <main class="wrap">
    <article class="card">
      <h1 style="margin-top:0">{$titleEsc}</h1>
HTML;

  if ($img) {
    $html .= "\n      <img class=\"thumb\" src=\"{$img}\" alt=\"\" />\n";
  }

  $html .= <<<HTML
      <p class="meta">Category: {$catEsc} · Source: {$srcEsc} · <time datetime="{$dateIso}">{$it['date']}</time></p>
      <p>{$descEsc}</p>
      <p><a class="btn" href="{$linkEsc}" target="_blank" rel="noopener">Read original article</a></p>
    </article>
    <p class="meta" style="text-align:center;margin-top:14px"><a href="{$BASE}/">← Back to PinoyTechFeed</a></p>
  </main>
</body>
</html>
HTML;

  file_put_contents($file, $html);
  $made++;
}

echo "✅ Built {$made} pages in /p\n";