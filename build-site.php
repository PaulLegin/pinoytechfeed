<?php
/**
 * build-site.php — makes per-post pages under /p/ with solid Open Graph tags
 * Works on any static host (build via GitHub Actions / local PHP)
 * - Reads local feed.xml
 * - Slug = slugify(title) + '-' + short md5(link) to keep unique/consistent
 * - Uses images.weserv.nl proxy to avoid hotlink/CORS blocks on previews
 */

date_default_timezone_set('Asia/Manila');

$SITE  = getenv('SITE_ORIGIN') ?: 'https://pinoytechfeed.pages.dev'; // update if you move domain
$FEED  = __DIR__.'/feed.xml';
$OUT   = __DIR__.'/p';

if (!is_dir($OUT)) mkdir($OUT, 0777, true);

function slugify($s) {
  $s = strtolower(trim($s));
  $s = preg_replace('~[^\pL\d]+~u', '-', $s);
  $s = preg_replace('~^-+|-+$~', '', $s);
  $s = preg_replace('~[^a-z0-9-]~', '', $s);
  if ($s === '') $s = 'post';
  if (strlen($s) > 70) $s = substr($s, 0, 70);
  return $s;
}
function short_hash($link) { return substr(md5($link), 0, 6); }

function guess_mime($url) {
  $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
  return match($ext) {
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    default => 'image/jpeg',
  };
}

/** proxy image to avoid hotlink/CORS blocks in FB/Twitter/Share UI */
function proxy_img($url) {
  if (!$url) return '';
  // images.weserv.nl expects host/path without scheme
  $u = preg_replace('~^https?://~i', '', $url);
  return 'https://images.weserv.nl/?url=' . rawurlencode($u);
}

if (!file_exists($FEED)) {
  fwrite(STDERR, "feed.xml not found.\n");
  exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($FEED);
if (!$xml) {
  fwrite(STDERR, "Cannot parse feed.xml\n");
  exit(1);
}
$ns = $xml->getNamespaces(true);

$pages = 0;
foreach ($xml->channel->item as $it) {
  $title = trim((string)$it->title) ?: 'Untitled';
  $link  = (string)$it->link;
  $desc  = trim(strip_tags((string)$it->description));
  $date  = strtotime((string)$it->pubDate) ?: time();
  $cat   = (string)$it->category ?: 'General';
  $src   = (string)$it->source ?: '';

  // image from enclosure / media:content
  $img = '';
  if (isset($it->enclosure) && $it->enclosure['url']) $img = (string)$it->enclosure['url'];
  if (!$img) {
    if (!empty($ns['media'])) {
      $m = $it->children($ns['media']);
      if (isset($m->content) && $m->content['url']) $img = (string)$m->content['url'];
      elseif (isset($m->thumbnail) && $m->thumbnail['url']) $img = (string)$m->thumbnail['url'];
    }
  }

  $slug = slugify($title) . '-' . short_hash($link);
  $file = $slug . '.html';
  $url  = rtrim($SITE, '/') . '/p/' . $file;

  $og_img = proxy_img($img);
  $og_w   = 1200;
  $og_h   = 630;

  $safe_title = htmlspecialchars($title, ENT_QUOTES);
  $safe_desc  = htmlspecialchars($desc ?: $title, ENT_QUOTES);
  $safe_link  = htmlspecialchars($link, ENT_QUOTES);
  $safe_src   = htmlspecialchars($src, ENT_QUOTES);
  $safe_cat   = htmlspecialchars($cat, ENT_QUOTES);

  $html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>'.$safe_title.' · PinoyTechFeed</title>
<link rel="canonical" href="'.$url.'" />
<meta name="robots" content="index,follow" />

<!-- Open Graph -->
<meta property="og:url" content="'.$url.'">
<meta property="og:type" content="article">
<meta property="og:site_name" content="PinoyTechFeed · Gadgets &amp; PH News">
<meta property="og:title" content="'.$safe_title.'">
<meta property="og:description" content="'.$safe_desc.'">';
  if ($og_img) {
    $html .= "\n".'<meta property="og:image" content="'.$og_img.'">
<meta property="og:image:width" content="'.$og_w.'">
<meta property="og:image:height" content="'.$og_h.'">
<meta name="twitter:card" content="summary_large_image">';
  } else {
    $html .= "\n".'<meta name="twitter:card" content="summary">';
  }
  $html .= '
<meta name="twitter:title" content="'.$safe_title.'">
<meta name="twitter:description" content="'.$safe_desc.'">

<style>
  :root{--bg:#0b1220;--fg:#e2e8f0;--muted:#94a3b8;--card:#0f172a;--brd:#213048;--accent:#22c55e}
  body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue","Noto Sans",Arial}
  .wrap{max-width:900px;margin:24px auto;padding:0 16px}
  a{color:#93c5fd}
  .card{background:var(--card);border:1px solid var(--brd);border-radius:12px;padding:16px}
  .meta{color:var(--muted);font-size:12px}
  .hero{width:100%;max-height:420px;object-fit:cover;border-radius:12px;border:1px solid var(--brd)}
  .btn{display:inline-block;margin:12px 8px 0 0;padding:10px 14px;border-radius:8px;border:1px solid var(--brd);background:#0b1324;color:var(--fg);text-decoration:none}
  .btn-accent{background:var(--accent);color:#06220f;border-color:#1da34f}
</style>
</head>
<body>
  <main class="wrap">
    <article class="card">
      <h1>'.$safe_title.'</h1>
      '.($og_img ? '<img class="hero" referrerpolicy="no-referrer" src="'.$og_img.'" alt="">' : '').'
      <p class="meta">'.date('M j, Y g:i A', $date).' · '.$safe_cat.($safe_src ? ' · Source: '.$safe_src : '').'</p>
      <p>'.nl2br($safe_desc).'</p>
      <p><a class="btn btn-accent" href="'.$safe_link.'" target="_blank" rel="noopener">Read original source</a>
         <a class="btn" href="/" >Back to PinoyTechFeed</a></p>
    </article>
  </main>
</body>
</html>';

  file_put_contents($OUT.'/'.$file, $html);
  $pages++;
}

echo "✅ Built {$pages} pages in /p\n";