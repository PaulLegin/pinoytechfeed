<?php
/**
 * build-site.php — create /p/*.html reader pages (with OG tags)
 */
date_default_timezone_set('Asia/Manila');

$SITE   = getenv('SITE_ORIGIN') ?: 'https://pinoytechfeed.pages.dev';
$FEED   = __DIR__.'/feed.xml';
$OUTDIR = __DIR__.'/p';
$OGDEF  = $SITE.'/og-default.jpg';   // upload this image to repo root

if(!is_dir($OUTDIR)) mkdir($OUTDIR,0777,true);

function shorten($s,$n=220){ $s=trim(preg_replace('/\s+/',' ',$s??'')); return mb_strlen($s)>$n?mb_substr($s,0,$n-1).'…':$s; }
function pick_img($img){ return ($img && preg_match('~^https?://~',$img)) ? $img : $GLOBALS['OGDEF']; }

$xml=@simplexml_load_file($FEED);
if(!$xml){ fwrite(STDERR,"feed.xml missing/invalid\n"); exit(1); }

$written=0;
foreach($xml->channel->item as $it){
  $title=(string)$it->title;
  $desc =(string)$it->description ?: $title;
  $date =(string)$it->pubDate;
  $cat  =(string)$it->category ?: 'General';
  $src  =(string)$it->source ?: '';
  $img  ='';
  if(isset($it->enclosure)){ $a=$it->enclosure->attributes(); if(!empty($a['url'])) $img=(string)$a['url']; }
  if(!$img){ $ns=$it->getNamespaces(true); if(isset($ns['media'])){ $m=$it->children($ns['media']); if(isset($m->content)){ $a=$m->content->attributes(); if(!empty($a['url'])) $img=(string)$a['url']; } } }
  $target=(string)$it->children('https://pinoytechfeed/pages/ns')->target;
  if(!$target) continue;

  $slug=basename(parse_url($target,PHP_URL_PATH));
  $og = pick_img($img);
  $safeTitle = htmlspecialchars($title,ENT_QUOTES);
  $safeDesc  = htmlspecialchars(shorten($desc,220),ENT_QUOTES);

  $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$safeTitle} · PinoyTechFeed</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<meta property="og:type" content="website">
<meta property="og:site_name" content="PinoyTechFeed">
<meta property="og:title" content="{$safeTitle}">
<meta property="og:description" content="{$safeDesc}">
<meta property="og:url" content="{$SITE}/p/{$slug}">
<meta property="og:image" content="{$og}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$safeTitle}">
<meta name="twitter:description" content="{$safeDesc}">
<meta name="twitter:image" content="{$og}">
<style>
  :root{--bg:#0b1220;--fg:#e2e8f0;--muted:#94a3b8;--accent:#22c55e;--card:#0f172a;--brd:#213048;}
  body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue","Noto Sans",Arial;line-height:1.55}
  .wrap{max-width:900px;margin:32px auto;padding:0 16px}
  .card{background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:18px}
  h1{margin:0 0 6px;font-size:28px}
  .meta{color:var(--muted);font-size:14px;margin-bottom:10px}
  .hero{width:100%;border-radius:10px;border:1px solid var(--brd);display:block;background:#0b1220}
  .btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:10px;text-decoration:none;border:1px solid var(--brd);background:#0b1324;color:#fff}
  .btn:hover{border-color:#375184}
  .visit{background:var(--accent);border-color:#1da34f;color:#06220f;font-weight:600}
  footer{color:#86a1be;text-align:center;margin:18px 0}
</style>
</head>
<body>
  <main class="wrap">
    <article class="card">
      <h1>{$safeTitle}</h1>
      <div class="meta">{$date} · Category: {$cat} · Source: {$src}</div>
      <img class="hero" src="{$og}" alt="">
      <p class="meta">This summary page lets social apps show a preview. Continue to the source:</p>
      <a class="btn visit" href="{$it->link}" target="_blank" rel="noopener">Read full article →</a>
      <a class="btn" href="{$SITE}" rel="noopener">Back to PinoyTechFeed</a>
    </article>
  </main>
  <footer>© PinoyTechFeed</footer>
</body>
</html>
HTML;

  file_put_contents($OUTDIR.'/'.$slug,$html);
  $written++;
}
echo "✅ Built {$written} pages in /p\n";
