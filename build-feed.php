<?php
/**
 * build-feed.php — RSS aggregator + local share pages (OG wrapper)
 * Host-agnostic: Cloudflare Pages, GitHub Pages+Actions, Netlify, Vercel, etc.
 * - Builds /feed.xml
 * - Creates /p/{slug}.html per item with OG tags, then redirects to source
 */

date_default_timezone_set('Asia/Manila');

/* =======================
   SOURCES
   ======================= */
$SOURCES = [
  // Gadgets → GSMArena (rss.app feed)
  ['url' => 'https://rss.app/feeds/f92kwVzjq4XUE6h3.xml', 'tag' => 'gadgets', 'label' => 'GSMArena',               'category' => 'Gadgets'],
  // PH News → GMA (rss.app feed)
  ['url' => 'https://rss.app/feeds/jvx17FqERQHCjtka.xml', 'tag' => 'ph',      'label' => 'GMA News',              'category' => 'PH News'],
  // Tech & Innovation → Interesting Engineering (rss.app feed)
  ['url' => 'https://rss.app/feeds/xoLNJ5ZwxVDYaRCl.xml', 'tag' => 'tech',    'label' => 'InterestingEngineering','category' => 'Tech & Innovation'],
];

/* =======================
   ORIGIN DETECTION
   ======================= */
function arg_val($argv, $key) {
  foreach (($argv ?? []) as $a) if (str_starts_with($a, "--$key=")) return substr($a, strlen($key) + 3);
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
  $cli = isset($argv) ? arg_val($argv, 'origin') : null;
  if ($cli) return normalize_origin($cli);
  foreach ([
    'SITE_ORIGIN',
    'CF_PAGES_URL','PAGES_URL',
    'NETLIFY_SITE_URL',
    'VERCEL_URL',
    'RENDER_EXTERNAL_URL',
    'RAILWAY_PUBLIC_DOMAIN',
    'GITHUB_PAGES_URL','PUBLIC_URL',
  ] as $k) {
    $v = getenv($k);
    if ($v) return normalize_origin($v);
  }
  return '';
}
$ORIGIN = detect_origin();
$CHANNEL_LINK_FALLBACK = 'https://pinoytechfeed.pages.dev'; // palitan kung lilipat ka

/* =======================
   CHANNEL META
   ======================= */
$CHANNEL = [
  'title'       => 'PinoyTechFeed',
  'link'        => $ORIGIN ?: $CHANNEL_LINK_FALLBACK,
  'description' => 'Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.',
  'language'    => 'en',
];

/* =======================
   HELPERS
   ======================= */
function http_get($url, $timeout = 20) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => $timeout,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_USERAGENT      => 'PinoyTechFeed-RSSFetcher/1.1',
  ]);
  $body = curl_exec($ch);
  curl_close($ch);
  return $body ?: null;
}
function ts($s) { $t = strtotime((string)$s); return $t ?: time(); }
function guess_mime($url) {
  $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
  return match($ext) { 'png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp', default=>'image/jpeg' };
}
function slugify($text, $max = 80) {
  $text = strtolower(trim($text));
  $text = preg_replace('~[^\pL\d]+~u', '-', $text);
  $text = trim($text, '-');
  $text = preg_replace('~[^-\w]+~', '', $text);
  if (strlen($text) > $max) $text = substr($text, 0, $max);
  $text = trim($text, '-');
  return $text ?: 'post';
}
function clean($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_XML1); }

/** Extract <item>s from a feed */
function extract_items($xml, $sourceUrl, $tag, $label, $categoryText) {
  $out = [];
  libxml_use_internal_errors(true);
  $sx = simplexml_load_string($xml);
  if (!$sx) return $out;
  $ns = $sx->getNamespaces(true);

  if (isset($sx->channel->item)) {
    foreach ($sx->channel->item as $itm) {
      $title = (string)$itm->title;
      $link  = (string)$itm->link;
      $desc  = (string)$itm->description;
      $date  = (string)$itm->pubDate;
      $img   = '';

      if (!empty($ns['media'])) {
        $media = $itm->children($ns['media']);
        if (isset($media->content)) {
          $attrs = $media->content->attributes();
          if (isset($attrs['url'])) $img = (string)$attrs['url'];
        }
        if (!$img && isset($media->thumbnail)) {
          $attrs = $media->thumbnail->attributes();
          if (isset($attrs['url'])) $img = (string)$attrs['url'];
        }
      }
      if (!$img && isset($itm->enclosure)) {
        $attrs = $itm->enclosure->attributes();
        if (isset($attrs['url'])) $img = (string)$attrs['url'];
      }
      if (!$img && $desc && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $desc, $m)) {
        $img = $m[1];
      }

      $out[] = [
        'title'     => trim($title),
        'link'      => $link ?: $sourceUrl,
        'desc'      => trim(strip_tags($desc)),
        'ts'        => ts($date),
        'img'       => $img,
        'tag'       => $tag,
        'source'    => $label,
        'category'  => $categoryText,
      ];
    }
  }
  return $out;
}

/** Build a local OG wrapper page for sharing */
function write_share_page($origin, $slug, $it) {
  $dir = __DIR__ . '/p';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $title = clean($it['title'] ?: 'PinoyTechFeed');
  $desc  = clean($it['desc']  ?: 'Read more on PinoyTechFeed');
  $img   = clean($it['img']   ?: ($origin . '/og-default.jpg'));
  $src   = clean($it['link']);
  $tag   = strtoupper($it['category'] ?? $it['tag'] ?? 'Tech');

  // 0.3s delay so the OG bot fetches this page’s tags before redirect
  $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title} · PinoyTechFeed</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="{$desc}">
<link rel="canonical" href="{$src}">

<!-- Open Graph -->
<meta property="og:type" content="article">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="{$desc}">
<meta property="og:image" content="{$img}">
<meta property="og:url" content="{$origin}/p/{$slug}.html">
<meta property="og:site_name" content="PinoyTechFeed">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$title}">
<meta name="twitter:description" content="{$desc}">
<meta name="twitter:image" content="{$img}">

<style>
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto;display:grid;place-content:center;min-height:100vh;background:#0b1220;color:#e2e8f0;text-align:center}
  a.btn{display:inline-block;margin-top:16px;padding:10px 14px;border:1px solid #213048;border-radius:8px;color:#e2e8f0;text-decoration:none}
  .tag{opacity:.7;font-size:12px;margin-top:8px}
</style>
<meta http-equiv="refresh" content="0.3;url={$src}">
<script>setTimeout(()=>location.replace("{$src}"),300);</script>
</head>
<body>
  <div>
    <div class="tag">PinoyTechFeed · {$tag}</div>
    <h1 style="max-width:900px;margin:12px auto 0;line-height:1.2">{$title}</h1>
    <p style="opacity:.85;max-width:800px;margin:8px auto 0">Redirecting to the full story…</p>
    <p><a class="btn" href="{$src}">Continue to source</a></p>
  </div>
</body>
</html>
HTML;

  file_put_contents("$dir/{$slug}.html", $html);
  return "{$origin}/p/{$slug}.html";
}

/* =======================
   Collect, dedupe, build pages
   ======================= */
$all = [];
foreach ($SOURCES as $src) {
  if (!$xml = http_get($src['url'])) continue;
  foreach (extract_items($xml, $src['url'], $src['tag'], $src['label'], $src['category']) as $it) {
    if (empty($it['link'])) continue;
    $all[$it['link']] = $it; // de-dupe by source link
  }
}
$items = array_values($all);
usort($items, fn($a,$b) => $b['ts'] <=> $a['ts']);
$items = array_slice($items, 0, 40);

/* Build local share pages and replace link */
$originForPages = $ORIGIN ?: $CHANNEL_LINK_FALLBACK;
$seenSlugs = [];
foreach ($items as &$it) {
  $base = slugify($it['title'] ?: 'post');
  $slug = $base;
  $i = 2;
  while (isset($seenSlugs[$slug])) { $slug = $base . '-' . $i++; }
  $seenSlugs[$slug] = true;

  $local = write_share_page($originForPages, $slug, [
    'title' => $it['title'],
    'desc'  => $it['desc'],
    'img'   => $it['img'],
    'link'  => $it['link'],
    'tag'   => $it['tag'],
    'category' => $it['category'],
  ]);

  $it['local'] = $local; // this will be used in RSS <link>
}
unset($it);

/* =======================
   Write RSS
   ======================= */
$now  = date(DATE_RSS);
$out  = [];
$out[] = '<?xml version="1.0" encoding="UTF-8"?>';
$out[] = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">';
$out[] = '<channel>';
if (!empty($ORIGIN)) {
  $out[] = '<atom:link href="'.$ORIGIN.'/feed.xml" rel="self" type="application/rss+xml"/>';
}
$out[] = '<title>'.clean($CHANNEL['title']).'</title>';
$out[] = '<link>'.clean($CHANNEL['link']).'</link>';
$out[] = '<description>'.clean($CHANNEL['description']).'</description>';
$out[] = '<language>'.$CHANNEL['language'].'</language>';
$out[] = '<lastBuildDate>'.$now.'</lastBuildDate>';
$out[] = '<ttl>30</ttl>';
$out[] = '<generator>PinoyTechFeed RSS Generator</generator>';

foreach ($items as $it) {
  $title = clean($it['title'] ?: 'Untitled');
  $link  = clean($it['local'] ?: $it['link']);  // ← local wrapper link!
  $desc  = clean($it['desc'] ?: '');
  $date  = date(DATE_RSS, $it['ts']);
  $img   = $it['img'];
  $cat   = clean($it['category']);
  $src   = clean($it['source']);

  $out[] = '<item>';
  $out[] =   "<title>{$title}</title>";
  $out[] =   "<link>{$link}</link>";
  $out[] =   "<description>{$desc}</description>";
  $out[] =   "<pubDate>{$date}</pubDate>";
  $out[] =   "<guid isPermaLink=\"true\">{$link}</guid>";
  $out[] =   "<category>{$cat}</category>";
  $out[] =   "<source>{$src}</source>";
  if ($img) {
    $type = guess_mime($img);
    $safe = clean($img);
    $out[] =   "<enclosure url=\"{$safe}\" type=\"{$type}\" />";
    $out[] =   "<media:content url=\"{$safe}\" medium=\"image\" />";
  }
  $out[] = '</item>';
}

$out[] = '</channel>';
$out[] = '</rss>';

file_put_contents(__DIR__.'/feed.xml', implode("\n", $out));
echo "✅ feed.xml written; ".count($items)." wrapper pages in /p\n";