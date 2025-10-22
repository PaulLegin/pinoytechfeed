<?php
/**
 * build-site.php — Build per-post HTML pages from feed.xml
 * Output: /p/<slug>.html with full Open Graph tags (thumbnail works)
 */

date_default_timezone_set('Asia/Manila');

$SITE = rtrim(getenv('SITE_ORIGIN') ?: 'https://pinoytechfeed.pages.dev', '/');
$FEED = __DIR__ . '/feed.xml';
$OUT  = __DIR__ . '/p';

if (!file_exists($FEED)) {
  fwrite(STDERR, "feed.xml not found\n");
  exit(1);
}
if (!is_dir($OUT)) mkdir($OUT, 0777, true);

function slugify($s) {
  $s = strtolower(trim($s));
  $s = preg_replace('~[^\pL\d]+~u', '-', $s);
  $s = preg_replace('~[-]+~', '-', $s);
  return trim($s, '-');
}
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** small util: try to guess image mime + dimensions for og:image tags */
function guess_mime($url) {
  $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
  return match($ext){
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp'=> 'image/webp',
    default => 'image/jpeg'
  };
}
function img_dim($url) {
  // best effort only (don’t block build if fails)
  $w = 1200; $h = 630;       // sensible default for link previews
  try {
    $ctx = stream_context_create(['http'=>['timeout'=>5],'https'=>['timeout'=>5]]);
    $bin = @file_get_contents($url, false, $ctx, 0, 200000);
    if ($bin) {
      $tmp = tempnam(sys_get_temp_dir(), 'ptf');
      file_put_contents($tmp, $bin);
      $info = @getimagesize($tmp);
      if ($info && isset($info[0],$info[1])) { $w=$info[0]; $h=$info[1]; }
      @unlink($tmp);
    }
  } catch(Throwable $e) {}
  return [$w,$h];
}

$xml = simplexml_load_file($FEED);
$xml->registerXPathNamespace('media','http://search.yahoo.com/mrss/');
$xml->registerXPathNamespace('atom','http://www.w3.org/2005/Atom');

$map = []; // source link -> site target

foreach ($xml->channel->item as $it) {
  $title = trim((string)$it->title);
  $srcLink = trim((string)$it->link);
  $dateStr = trim((string)$it->pubDate);
  $desc    = trim((string)$it->description);
  $cat     = trim((string)$it->category);
  $source  = trim((string)$it->source);

  // image (prefer enclosure/media:content)
  $img = '';
  if (isset($it->enclosure['url'])) $img = (string)$it->enclosure['url'];
  if (!$img) {
    $mc = $it->children('http://search.yahoo.com/mrss/');
    if (isset($mc->content['url'])) $img = (string)$mc->content['url'];
  }

  // slug + target url
  $slug = slugify($title);
  // make it unique-ish by tacking a 5-char hash of link if slug collides
  $outfile = "$OUT/$slug.html";
  if (file_exists($outfile)) {
    $slug .= '-' . substr(md5($srcLink), 0, 5);
    $outfile = "$OUT/$slug.html";
  }
  $target = "$SITE/p/$slug.html";

  // OG image dims (best effort)
  [$ogW,$ogH] = img_dim($img);
  $mime = guess_mime($img);

  $dateIso = $dateStr ? date(DATE_ATOM, strtotime($dateStr)) : date(DATE_ATOM);

  $html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>'.esc($title).' · PinoyTechFeed</title>

<link rel="canonical" href="'.esc($srcLink).'" />

<meta name="description" content="'.esc($desc ?: $title).'" />

<!-- Open Graph -->
<meta property="og:site_name" content="PinoyTechFeed" />
<meta property="og:type" content="article" />
<meta property="og:title" content="'.esc($title).'" />
<meta property="og:description" content="'.esc($desc ?: $title).'" />
<meta property="og:url" content="'.esc($target).'" />'.
($img ? '
<meta property="og:image" content="'.esc($img).'" />
<meta property="og:image:secure_url" content="'.esc($img).'" />
<meta property="og:image:type" content="'.esc($mime).'" />
<meta property="og:image:width" content="'.(int)$ogW.'" />
<meta property="og:image:height" content="'.(int)$ogH.'" />' : '') . '

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="'.esc($title).'" />
<meta name="twitter:description" content="'.esc($desc ?: $title).'" />'.
($img ? '<meta name="twitter:image" content="'.esc($img).'" />' : '') . '

<meta name="author" content="'.esc($source ?: 'PinoyTechFeed').'" />
<meta property="article:published_time" content="'.esc($dateIso).'" />

<style>
  :root{--bg:#0b1220;--fg:#e2e8f0;--muted:#94a3b8;--card:#0f172a;--brd:#213048;--accent:#22c55e}
  body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue","Noto Sans",Arial}
  .wrap{max-width:860px;margin:28px auto;padding:0 16px}
  a{color:#9dc1ff}
  .card{background:var(--card);border:1px solid var(--brd);border-radius:12px;padding:18px}
  .meta{color:var(--muted);font-size:13px;margin-top:8px}
  .btn{display:inline-block;margin-top:12px;border:1px solid var(--brd);border-radius:8px;padding:8px 12px;text-decoration:none;background:#0b1324;color:var(--fg)}
  .btn:hover{border-color:#38598a}
  img.hero{width:100%;max-height:420px;object-fit:cover;border-radius:10px;border:1px solid var(--brd);background:#0b1220}
</style>
</head>
<body>
  <main class="wrap">
    <article class="card">
      '.($img ? '<img class="hero" src="'.esc($img).'" alt="" referrerpolicy="no-referrer" />' : '').'
      <h1>'.esc($title).'</h1>
      <div class="meta">Category: '.esc($cat ?: 'General').' · Source: '.esc($source ?: 'Source').'</div>
      <p>'.nl2br(esc($desc ?: $title)).'</p>
      <a class="btn" href="'.esc($srcLink).'" rel="noopener nofollow" target="_blank">Read the full article on '.esc($source ?: 'original site').' →</a>
    </article>
  </main>
</body>
</html>';

  file_put_contents($outfile, $html);
  $map[$srcLink] = $target;
}

/** OPTIONAL: write a tiny map json (can be used by share.html if desired) */
file_put_contents("$OUT/map.json", json_encode($map, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

echo "✅ Built ".count($map)." pages in /p\n";