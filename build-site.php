<?php
/**
 * build-site.php — generates /p/*.html article pages from feed.xml
 * - Works in GitHub Actions (no server needed)
 * - Each page has OG/Twitter tags so Facebook/Twitter show thumbnails
 * - Target URL format: https://pinoytechfeed.pages.dev/p/<slug>.html
 */

date_default_timezone_set('Asia/Manila');

function slugify($s){
  $s = preg_replace('~[^\pL\d]+~u', '-', $s);
  $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
  $s = preg_replace('~[^-\w]+~', '', $s);
  $s = trim($s, '-');
  $s = preg_replace('~-+~', '-', $s);
  $s = strtolower($s);
  return $s ?: 'post';
}
function pick($node, $tag){
  $n = $node->getElementsByTagName($tag);
  return $n->length ? trim($n->item(0)->textContent) : '';
}
function firstAttr($node, $tag, $attr){
  $n = $node->getElementsByTagName($tag);
  if ($n->length){
    $a = $n->item(0)->attributes;
    if ($a && $a->getNamedItem($attr)) return trim($a->getNamedItem($attr)->nodeValue);
  }
  return '';
}
function guess_mime($url){
  $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
  return $ext==='png' ? 'image/png' : ($ext==='gif' ? 'image/gif' : ($ext==='webp' ? 'image/webp' : 'image/jpeg'));
}

$ORIGIN = getenv('SITE_ORIGIN') ?: 'https://pinoytechfeed.pages.dev';
$OUTDIR = __DIR__ . '/p';
@mkdir($OUTDIR, 0777, true);

// Load feed.xml
$feed = @file_get_contents(__DIR__.'/feed.xml');
if (!$feed){ fwrite(STDERR, "feed.xml not found.\n"); exit(1); }

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadXML($feed);
libxml_clear_errors();
$items = $dom->getElementsByTagName('item');

$pages = [];
foreach ($items as $it){
  $title = pick($it,'title');
  $link  = pick($it,'link');
  $desc  = pick($it,'description');
  $date  = pick($it,'pubDate');
  $cat   = pick($it,'category');
  $src   = pick($it,'source');
  $img   = firstAttr($it,'enclosure','url');
  if (!$img) $img = firstAttr($it,'media:content','url');

  $slug  = slugify($title);
  // make slug deterministic by appending short hash of link (prevents clashes)
  $slug .= '-' . substr(sha1($link),0,6);
  $pageUrl = rtrim($ORIGIN,'/') . '/p/' . $slug . '.html';

  // Minimal HTML page with full OG/Twitter
  $safeTitle = htmlspecialchars($title, ENT_QUOTES);
  $safeDesc  = htmlspecialchars($desc ?: $title, ENT_QUOTES);
  $safeImg   = htmlspecialchars($img, ENT_QUOTES);
  $safeSrc   = htmlspecialchars($src ?: parse_url($link,PHP_URL_HOST), ENT_QUOTES);
  $safeLink  = htmlspecialchars($link, ENT_QUOTES);
  $pubISO    = date('c', strtotime($date ?: 'now'));

  $html = '<!doctype html><html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>'.$safeTitle.' · PinoyTechFeed</title>
<link rel="canonical" href="'.$pageUrl.'">
<meta name="description" content="'.$safeDesc.'">

<meta property="og:type" content="article">
<meta property="og:site_name" content="PinoyTechFeed">
<meta property="og:title" content="'.$safeTitle.'">
<meta property="og:description" content="'.$safeDesc.'">
<meta property="og:url" content="'.$pageUrl.'">'.
($img ? '<meta property="og:image" content="'.$safeImg.'">
<meta property="og:image:type" content="'.guess_mime($img).'">' : '') .'

<meta name="twitter:card" content="'.($img?'summary_large_image':'summary').'">
<meta name="twitter:title" content="'.$safeTitle.'">
<meta name="twitter:description" content="'.$safeDesc.'">'.
($img ? '<meta name="twitter:image" content="'.$safeImg.'">' : '') .'

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "NewsArticle",
  "headline": "'.$safeTitle.'",
  "datePublished": "'.$pubISO.'",
  "dateModified": "'.$pubISO.'",
  "mainEntityOfPage": {"@type":"WebPage","@id":"'.$pageUrl.'"},
  "image": '.($img?json_encode($img):'[]').',
  "author": {"@type":"Organization","name":"'.$safeSrc.'"},
  "publisher": {"@type":"Organization","name":"PinoyTechFeed"}
}
</script>

<style>
:root{--bg:#0b1220;--fg:#e2e8f0;--muted:#94a3b8;--card:#0f172a;--brd:#213048;--accent:#22c55e}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue","Noto Sans",Arial}
.wrap{max-width:860px;margin:0 auto;padding:18px}
a{color:#9dd7ff}
.card{background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:18px}
h1{margin:0 0 10px;line-height:1.25}
.meta{color:var(--muted);font-size:13px;margin-bottom:14px}
.hero{width:100%;border-radius:12px;border:1px solid var(--brd);max-height:480px;object-fit:cover;background:#0b1220}
.btn{display:inline-block;margin-top:14px;padding:9px 12px;border:1px solid var(--brd);border-radius:10px;background:#0b1324;color:var(--fg);text-decoration:none}
.btn-accent{background:var(--accent);border-color:#1da34f;color:#06220f}
</style>
</head>
<body>
  <div class="wrap">
    <a class="btn" href="/">← Home</a>
    <article class="card">
      <h1>'.$safeTitle.'</h1>
      <div class="meta">'.$safeSrc.' · '.htmlspecialchars($cat).' · '.htmlspecialchars($date).'</div>'.
      ($img ? '<img class="hero" src="'.$safeImg.'" alt="">' : '').
      '<p style="margin-top:14px">'.nl2br($safeDesc).'</p>
      <p><a class="btn btn-accent" href="'.$safeLink.'" rel="noopener" target="_blank">Read original</a></p>
    </article>
  </div>
</body></html>';

  file_put_contents($OUTDIR.'/'.$slug.'.html', $html);
  $pages[] = $slug.'.html';
}

echo "Built ".count($pages)." pages in /p\n";