<?php
/**
 * build-feed.php — RSS aggregator + Share Cards
 * Generates:
 *   - /feed.xml  (merged feed)
 *   - /c/{id}.html (share card pages with OG tags that redirect to source)
 *
 * Works on Cloudflare Pages, GitHub Pages+Actions, Netlify, Vercel, etc.
 */
date_default_timezone_set('Asia/Manila');

/* =======================
   CONFIG: Your sources
   ======================= */
$SOURCES = [
  // Gadgets → GSMArena (rss.app feed)
  ['url' => 'https://rss.app/feeds/f92kwVzjq4XUE6h3.xml', 'tag' => 'gadgets', 'label' => 'GSMArena',                'category' => 'Gadgets'],
  // PH News → GMA (rss.app feed)
  ['url' => 'https://rss.app/feeds/jvx17FqERQHCjtka.xml', 'tag' => 'ph',      'label' => 'GMA News',               'category' => 'PH News'],
  // Tech & Innovation → Interesting Engineering (rss.app feed)
  ['url' => 'https://rss.app/feeds/xoLNJ5ZwxVDYaRCl.xml', 'tag' => 'tech',    'label' => 'Interesting Engineering','category' => 'Tech & Innovation'],
];

/* =======================
   Detect site origin (for absolute URLs)
   ======================= */
function arg_val($argv, $key) {
  foreach ($argv as $a) if (str_starts_with($a, "--$key=")) return substr($a, strlen($key) + 3);
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
    'CF_PAGES_URL', 'PAGES_URL',
    'NETLIFY_SITE_URL',
    'VERCEL_URL',
    'RENDER_EXTERNAL_URL',
    'RAILWAY_PUBLIC_DOMAIN',
    'GITHUB_PAGES_URL', 'PUBLIC_URL',
  ];
  foreach ($candidates as $k) {
    $v = getenv($k);
    if ($v) return normalize_origin($v);
  }
  return '';
}
$ORIGIN = detect_origin();
$CHANNEL_LINK_FALLBACK = 'https://pinoytechfeed.pages.dev'; // change if you move

/* =======================
   Channel meta
   ======================= */
$CHANNEL = [
  'title'       => 'PinoyTechFeed',
  'link'        => $ORIGIN ?: $CHANNEL_LINK_FALLBACK,
  'description' => 'Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.',
  'language'    => 'en',
];

/* =======================
   Helpers
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
  return match($ext) {
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    default => 'image/jpeg',
  };
}
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

      // media:content / media:thumbnail
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
      // enclosure
      if (!$img && isset($itm->enclosure)) {
        $attrs = $itm->enclosure->attributes();
        if (isset($attrs['url'])) $img = (string)$attrs['url'];
      }
      // sniff first <img> in description
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

/* =======================
   Collect & dedupe
   ======================= */
$all = [];
foreach ($SOURCES as $src) {
  if (!$xml = http_get($src['url'])) continue;
  foreach (extract_items($xml, $src['url'], $src['tag'], $src['label'], $src['category']) as $it) {
    if (!empty($it['link'])) $all[$it['link']] = $it; // de-dupe by link
  }
}
$items = array_values($all);
usort($items, fn($a,$b) => $b['ts'] <=> $a['ts']);
$items = array_slice($items, 0, 40);

/* =======================
   Ensure /c exists (share cards folder)
   ======================= */
@mkdir(__DIR__ . '/c', 0775, true);

/* =======================
   Write RSS + Share Cards
   ======================= */
$now  = date(DATE_RSS);
$xml  = [];
$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$xml[] = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:ptf="https://pinoytechfeed">';
$xml[] = '<channel>';

if (!empty($ORIGIN)) {
  $xml[] = '<atom:link href="'.$ORIGIN.'/feed.xml" rel="self" type="application/rss+xml"/>';
}

$xml[] = '<title>'.htmlspecialchars($CHANNEL['title'], ENT_XML1).'</title>';
$xml[] = '<link>'.htmlspecialchars($CHANNEL['link'], ENT_XML1).'</link>';
$xml[] = '<description>'.htmlspecialchars($CHANNEL['description'], ENT_XML1).'</description>';
$xml[] = '<language>'.$CHANNEL['language'].'</language>';
$xml[] = '<lastBuildDate>'.$now.'</lastBuildDate>';
$xml[] = '<ttl>30</ttl>';
$xml[] = '<generator>PinoyTechFeed RSS Generator</generator>';

foreach ($items as $it) {
  // ---- Build share card ----
  $id        = substr(md5($it['link']), 0, 10);
  $shareUrl  = !empty($ORIGIN) ? $ORIGIN . "/c/{$id}.html" : $it['link'];
  $dest      = $it['link'];

  $ogTitle   = htmlspecialchars($it['title'] ?: 'Untitled', ENT_QUOTES);
  $ogDesc    = htmlspecialchars($it['desc']  ?: '',        ENT_QUOTES);
  $ogImage   = htmlspecialchars($it['img']   ?: '',        ENT_QUOTES);
  $canonDest = htmlspecialchars($dest,                       ENT_QUOTES);

  // Card HTML
  $og = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$ogTitle} · PinoyTechFeed</title>
<link rel="canonical" href="{$canonDest}">
<meta property="og:type" content="article">
<meta property="og:title" content="{$ogTitle}">
<meta property="og:description" content="{$ogDesc}">
<meta property="og:url" content="{$shareUrl}">
HTML;

  if (!empty($ogImage)) {
    $og .= "\n<meta property=\"og:image\" content=\"{$ogImage}\">";
  }

  $og .= <<<HTML

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$ogTitle}">
<meta name="twitter:description" content="{$ogDesc}">
HTML;

  if (!empty($ogImage)) {
    $og .= "\n<meta name=\"twitter:image\" content=\"{$ogImage}\">";
  }

  $og .= <<<HTML

<meta http-equiv="refresh" content="1;url={$canonDest}">
<style>
  body{background:#0b1220;color:#e2e8f0;font:16px system-ui;-webkit-font-smoothing:antialiased;text-align:center;padding:48px}
  a{color:#9ddfff}
</style>
</head>
<body>
  <h1>Redirecting…</h1>
  <p>Sending you to the full article. If it doesn't load, <a href="{$canonDest}">tap here</a>.</p>
</body>
</html>
HTML;

  file_put_contents(__DIR__ . "/c/{$id}.html", $og);

  // ---- Write RSS item ----
  $title = htmlspecialchars($it['title'] ?: 'Untitled', ENT_XML1);
  $link  = htmlspecialchars($it['link'], ENT_XML1);
  $desc  = htmlspecialchars($it['desc'] ?: '', ENT_XML1);
  $date  = date(DATE_RSS, $it['ts']);
  $img   = $it['img'];
  $cat   = htmlspecialchars($it['category'], ENT_XML1);
  $src   = htmlspecialchars($it['source'],   ENT_XML1);
  $shareSafe = htmlspecialchars($shareUrl,   ENT_XML1);

  $xml[] = '<item>';
  $xml[] =   "<title>{$title}</title>";
  $xml[] =   "<link>{$link}</link>";
  $xml[] =   "<description>{$desc}</description>";
  $xml[] =   "<pubDate>{$date}</pubDate>";
  $xml[] =   "<guid isPermaLink=\"true\">{$link}</guid>";
  $xml[] =   "<category>{$cat}</category>";
  $xml[] =   "<source>{$src}</source>";
  $xml[] =   "<ptf:share>{$shareSafe}</ptf:share>"; // <-- your share-card URL
  if ($img) {
    $type = guess_mime($img);
    $safe = htmlspecialchars($img, ENT_XML1);
    $xml[] =   "<enclosure url=\"{$safe}\" type=\"{$type}\" />";
    $xml[] =   "<media:content url=\"{$safe}\" medium=\"image\" />";
  }
  $xml[] = '</item>';
}

$xml[] = '</channel>';
$xml[] = '</rss>';

file_put_contents(__DIR__.'/feed.xml', implode("\n", $xml));
echo "✅ feed.xml + /c/*.html written".(!empty($ORIGIN) ? " for {$ORIGIN}" : " (no origin set)")."\n";