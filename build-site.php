<?php
/**
 * build-site.php — generates /p/*.html pages from feed.xml
 * and rewrites feed.xml adding <ptf:* /> fields for the share dashboard.
 * Keep this beside build-feed.php (same folder).
 */
date_default_timezone_set('Asia/Manila');

$SITE = rtrim(getenv('SITE_ORIGIN') ?: 'https://pinoytechfeed.pages.dev', '/');
$FEED = __DIR__.'/feed.xml';
$OUT  = __DIR__.'/p';
if (!is_dir($OUT)) mkdir($OUT, 0777, true);

/* ---------- helpers ---------- */
function pick_img($item, $ns) {
  // media:content / enclosure
  if (!empty($ns['media'])) {
    $m = $item->children($ns['media']);
    if (isset($m->content)) {
      $a = $m->content->attributes();
      if (!empty($a['url'])) return (string)$a['url'];
    }
  }
  if (isset($item->enclosure)) {
    $a = $item->enclosure->attributes();
    if (!empty($a['url'])) return (string)$a['url'];
  }
  // sniff in description
  $desc = (string)$item->description;
  if ($desc && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $desc, $m)) {
    return $m[1];
  }
  return '';
}
function short($s, $n=220){ $s=trim(strip_tags($s)); return mb_strlen($s)>$n? mb_substr($s,0,$n-1).'…' : $s; }
function slugify($s){
  $s = mb_strtolower($s,'UTF-8');
  $s = preg_replace('~[^\pL\d]+~u','-',$s);
  $s = trim($s,'-');
  $s = preg_replace('~[^-\w]+~u','',$s);
  if (empty($s)) $s = 'post';
  return $s;
}
function shash($link){ return substr(sha1($link), 0, 6); } // JS-free stable slug suffix
function write_file($path,$html){ file_put_contents($path,$html); }

/* ---------- load feed ---------- */
libxml_use_internal_errors(true);
$xml = simplexml_load_file($FEED);
if (!$xml) { fwrite(STDERR,"feed.xml not found or invalid\n"); exit(1); }
$ns  = $xml->getNamespaces(true);

/* ---------- build pages + collect enriched items ---------- */
$items_out = [];
foreach ($xml->channel->item as $it) {
  $title = trim((string)$it->title) ?: 'Untitled';
  $link  = trim((string)$it->link);
  $desc  = (string)$it->description;
  $date  = (string)$it->pubDate;
  $cat   = (string)$it->category;
  $src   = (string)$it->source;
  $ts    = strtotime($date) ?: time();
  $img   = pick_img($it, $ns);

  $slug  = slugify($title);
  $slug .= '-' . shash($link);
  $file  = $slug . '.html';
  $url   = $SITE . '/p/' . $file;

  // ---------- page HTML (with OG/Twitter) ----------
  $ogimg = $img ?: ($SITE.'/og-default.jpg'); // optional: drop a default image
  $ogw = 1200; $ogh = 630;

  $metadesc = short($desc ?: $title, 200);

  $page = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · PinoyTechFeed</title>
<link rel="canonical" href="{$url}">
<meta name="description" content="{$metadesc}">

<!-- Open Graph -->
<meta property="og:type" content="article">
<meta property="og:site_name" content="PinoyTechFeed">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="{$metadesc}">
<meta property="og:url" content="{$url}">
<meta property="og:image" content="{$ogimg}">
<meta property="og:image:width" content="{$ogw}">
<meta property="og:image:height" content="{$ogh}">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$title}">
<meta name="twitter:description" content="{$metadesc}">
<meta name="twitter:image" content="{$ogimg}">

<style>
  :root { --bg:#0b1220; --fg:#e2e8f0; --muted:#94a3b8; --card:#0f172a; --brd:#213048; --accent:#22c55e;}
  body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue","Noto Sans",Arial;}
  .wrap{max-width:860px;margin:24px auto;padding:0 16px}
  .card{background:var(--card);border:1px solid var(--brd);border-radius:12px;padding:16px}
  .title{font-size:24px;font-weight:700;line-height:1.25;margin:0 0 8px}
  .meta{color:var(--muted);font-size:13px;margin-bottom:12px}
  .thumb{width:100%;max-height:460px;object-fit:cover;border-radius:10px;border:1px solid var(--brd);background:#0b1220}
  .btn{display:inline-block;margin-top:16px;padding:10px 14px;border-radius:10px;background:var(--accent);color:#05240d;text-decoration:none;border:1px solid #169245}
  header{display:flex;align-items:center;justify-content:space-between;margin:10px 0 18px}
  header a{color:#8ecdfc}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>📣 <a href="{$SITE}" style="text-decoration:none">PinoyTechFeed</a></div>
    <a href="{$SITE}/share.html">Share Dashboard</a>
  </header>

  <article class="card">
    <h1 class="title">{$title}</h1>
    <div class="meta">Category: {$cat} · Source: {$src} · <time datetime="{$date}">{$date}</time></div>
    {$img ? '<img class="thumb" src="'.$img.'" alt="">' : ''}
    <p style="margin-top:12px;line-height:1.6">{$metadesc}</p>
    <a class="btn" href="{$link}" rel="noopener nofollow" target="_blank">Read the full article on {$src}</a>
  </article>
</div>
</body>
</html>
HTML;

  write_file($OUT.'/'.$file, $page);

  $items_out[] = [
    'title'=>$title,'link'=>$link,'desc'=>$metadesc,'date'=>$date,'ts'=>$ts,
    'img'=>$img,'category'=>$cat,'source'=>$src,
    'slug'=>$slug,'target'=>$url
  ];
}

/* ---------- rewrite feed.xml with <ptf:*> fields ---------- */
$now = date(DATE_RSS);
$buf = [];
$buf[] = '<?xml version="1.0" encoding="UTF-8"?>';
$buf[] = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:ptf="https://pinoytechfeed.pages.dev/ns">';
$buf[] = '<channel>';
$buf[] = '<title>PinoyTechFeed</title>';
$buf[] = '<link>'.$SITE.'</link>';
$buf[] = '<description>Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.</description>';
$buf[] = '<language>en</language>';
$buf[] = '<lastBuildDate>'.$now.'</lastBuildDate>';
$buf[] = '<generator>PinoyTechFeed Site Builder</generator>';

foreach ($items_out as $p) {
  $title = htmlspecialchars($p['title'], ENT_XML1);
  $link  = htmlspecialchars($p['link'],  ENT_XML1);
  $desc  = htmlspecialchars($p['desc'],  ENT_XML1);
  $cat   = htmlspecialchars($p['category'], ENT_XML1);
  $src   = htmlspecialchars($p['source'],   ENT_XML1);
  $img   = htmlspecialchars($p['img'],      ENT_XML1);
  $tgt   = htmlspecialchars($p['target'],   ENT_XML1);
  $slug  = htmlspecialchars($p['slug'],     ENT_XML1);
  $date  = date(DATE_RSS, $p['ts']);

  $buf[] = '<item>';
  $buf[] =   '<title>'.$title.'</title>';
  $buf[] =   '<link>'.$link.'</link>';
  $buf[] =   '<ptf:target>'.$tgt.'</ptf:target>';
  $buf[] =   '<description>'.$desc.'</description>';
  $buf[] =   '<pubDate>'.$date.'</pubDate>';
  $buf[] =   '<guid isPermaLink="true">'.$link.'</guid>';
  $buf[] =   '<category>'.$cat.'</category>';
  $buf[] =   '<source>'.$src.'</source>';
  if (!empty($img)) {
    // keep enclosure for compatibility
    $type = 'image/jpeg';
    $ext = strtolower(pathinfo(parse_url($img, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ($ext==='png') $type='image/png';
    elseif ($ext==='gif') $type='image/gif';
    elseif ($ext==='webp') $type='image/webp';
    $buf[] =   '<enclosure url="'.$img.'" type="'.$type.'" />';
    $buf[] =   '<media:content url="'.$img.'" medium="image" />';
    $buf[] =   '<ptf:img>'.$img.'</ptf:img>';
  }
  $buf[] =   '<ptf:slug>'.$slug.'</ptf:slug>';
  $buf[] =   '<ptf:ts>'.$p['ts'].'</ptf:ts>';
  $buf[] = '</item>';
}
$buf[] = '</channel>';
$buf[] = '</rss>';

file_put_contents($FEED, implode("\n", $buf));
echo "✅ Built ".count($items_out)." pages in /p and rewrote feed.xml\n";