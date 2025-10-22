<?php
/**
 * build-site.php — Reads feed.xml and generates /p/*.html pages
 * Pages include correct Open Graph tags and use the original image.
 */
date_default_timezone_set('Asia/Manila');

$SITE_ORIGIN = rtrim(getenv('SITE_ORIGIN') ?: 'https://pinoytechfeed.pages.dev','/');

$FEED = __DIR__.'/feed.xml';
$OUT  = __DIR__.'/p';
if(!is_dir($OUT)) mkdir($OUT,0777,true);

function img_proxy($url){
  if(!$url) return '';
  // Force JPEG + 1200x630 to please FB
  return 'https://wsrv.nl/?url='.rawurlencode($url).'&w=1200&h=630&fit=cover&output=jpg';
}

$xml = file_get_contents($FEED);
$dom = new DOMDocument();
$dom->loadXML($xml);
$xp = new DOMXPath($dom);
$xp->registerNamespace('ptf','https://pinoytechfeed.pages.dev/ns');

$items = $dom->getElementsByTagName('item');
foreach($items as $it){
  $title = $it->getElementsByTagName('title')->item(0)->textContent;
  $guid  = $it->getElementsByTagName('guid')->item(0)->textContent; // our /p/...html
  $desc  = $it->getElementsByTagName('description')->item(0)->textContent;
  $date  = $it->getElementsByTagName('pubDate')->item(0)->textContent;
  $cat   = $it->getElementsByTagName('category')->item(0)->textContent;
  $src   = $it->getElementsByTagName('source')->item(0)->textContent;
  $ptf   = $xp->query('ptf:target',$it)->item(0);
  $orig  = $ptf ? $ptf->textContent : '';

  $encl  = $it->getElementsByTagName('enclosure')->item(0);
  $img   = $encl ? $encl->getAttribute('url') : '';

  $slug = basename(parse_url($guid,PHP_URL_PATH),'.html');
  $thumb = img_proxy($img);

  $html = '<!doctype html><html lang="en"><head>'.
    '<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>'.
    '<title>'.htmlspecialchars($title).' · PinoyTechFeed</title>'.

    // Open Graph
    '<meta property="og:type" content="article">'.
    '<meta property="og:site_name" content="PinoyTechFeed">'.
    '<meta property="og:title" content="'.htmlspecialchars($title).'">'.
    '<meta property="og:description" content="'.htmlspecialchars($desc?:$title).'">'.
    '<meta property="og:url" content="'.$SITE_ORIGIN.'/p/'.$slug.'.html">'.
    ($thumb?'<meta property="og:image" content="'.$thumb.'"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">':'').

    // Twitter
    ($thumb?'<meta name="twitter:card" content="summary_large_image">':'<meta name="twitter:card" content="summary">').
    '<meta name="twitter:title" content="'.htmlspecialchars($title).'">'.
    '<meta name="twitter:description" content="'.htmlspecialchars($desc?:$title).'">'.
    ($thumb?'<meta name="twitter:image" content="'.$thumb.'">':'').

    '<meta name="robots" content="index,follow">'.
    '<style>body{margin:0;background:#0b1220;color:#e2e8f0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial}'.
    '.wrap{max-width:900px;margin:20px auto;padding:0 16px}'.
    'a{color:#8ab4ff} .btn{display:inline-block;margin:8px 8px 0 0;padding:10px 14px;border:1px solid #2b3e60;border-radius:10px;background:#0f172a;color:#e2e8f0;text-decoration:none}'.
    '.hero{width:100%;max-height:520px;object-fit:cover;border:1px solid #22324f;border-radius:12px;background:#0a1020}'.
    '.meta{color:#94a3b8;margin:6px 0 14px;font-size:14px}'.
    '</style>'.
  '</head><body><main class="wrap">'.
    '<h1>'.htmlspecialchars($title).'</h1>'.
    ($thumb?'<img class="hero" src="'.$thumb.'" alt="">':'').
    '<div class="meta">'.date('M d, Y g:i A',strtotime($date)).' · Category: '.htmlspecialchars($cat).' · Source: '.htmlspecialchars($src).'</div>'.
    '<p>'.nl2br(htmlspecialchars($desc?:$title)).'</p>'.
    ($orig?'<p><a class="btn" href="'.$orig.'" rel="noopener" target="_blank">Read full story on '.$src.'</a></p>':'').
    '<p><a class="btn" href="/">⬅ Back to PinoyTechFeed</a></p>'.
  '</main></body></html>';

  file_put_contents($OUT.'/'.$slug.'.html',$html);
}

echo "✅ built /p pages (".count($items).") for {$SITE_ORIGIN}\n";