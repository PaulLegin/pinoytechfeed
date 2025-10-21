<?php
// build-feed.php — PinoyTechFeed RSS aggregator
// Maps sources to categories and writes /feed.xml

date_default_timezone_set('Asia/Manila');

// Map your 3 feeds to stable tags + human labels + category text
$SOURCES = [
  // Gadgets → GSMArena (rss.app feed)
  ['url' => 'https://rss.app/feeds/f92kwVzjq4XUE6h3.xml', 'tag' => 'gadgets', 'label' => 'GSMArena', 'category' => 'Gadgets'],
  // PH News → GMA (rss.app feed)
  ['url' => 'https://rss.app/feeds/jvx17FqERQHCjtka.xml', 'tag' => 'ph', 'label' => 'GMA News', 'category' => 'PH News'],
  // Tech & Innovation → Inquirer (rss.app feed)
  ['url' => 'https://rss.app/feeds/ICCUIF4kF5MlzXJs.xml', 'tag' => 'tech', 'label' => 'Inquirer Tech', 'category' => 'Tech & Innovation'],
];

$CHANNEL = [
  'title'       => 'PinoyTechFeed',
  'link'        => 'https://pinoytechfeed.netlify.app',
  'description' => 'Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.',
  'language'    => 'en',
];

function http_get($url, $timeout = 15) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => $timeout,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_USERAGENT      => 'PinoyTechFeed-RSSFetcher/1.0',
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
      if (!$img && $desc) {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $desc, $m)) {
          $img = $m[1];
        }
      }

      $out[] = [
        'title'     => trim($title),
        'link'      => $link ?: $sourceUrl,
        'desc'      => trim(strip_tags($desc)),
        'ts'        => ts($date),
        'img'       => $img,
        'tag'       => $tag,          // internal tag for UI
        'source'    => $label,        // label for display
        'category'  => $categoryText, // <category> text in RSS
      ];
    }
  }
  return $out;
}

// Collect + dedupe by link
$all = [];
foreach ($SOURCES as $src) {
  if (!$xml = http_get($src['url'])) continue;
  foreach (extract_items($xml, $src['url'], $src['tag'], $src['label'], $src['category']) as $it) {
    if (!empty($it['link'])) $all[$it['link']] = $it;
  }
}

$items = array_values($all);
usort($items, fn($a,$b) => $b['ts'] <=> $a['ts']);
$items = array_slice($items, 0, 40); // keep latest 40

// Write RSS
$now  = date(DATE_RSS);
$xml  = [];
$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$xml[] = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">';
$xml[] = '<channel>';
$xml[] = '<atom:link href="https://pinoytechfeed.netlify.app/feed.xml" rel="self" type="application/rss+xml"/>';
$xml[] = '<title>'.htmlspecialchars($CHANNEL['title'], ENT_XML1).'</title>';
$xml[] = '<link>'.$CHANNEL['link'].'</link>';
$xml[] = '<description>'.htmlspecialchars($CHANNEL['description'], ENT_XML1).'</description>';
$xml[] = '<language>'.$CHANNEL['language'].'</language>';
$xml[] = '<lastBuildDate>'.$now.'</lastBuildDate>';
$xml[] = '<ttl>30</ttl>';
$xml[] = '<generator>PinoyTechFeed RSS Generator</generator>';

foreach ($items as $it) {
  $title = htmlspecialchars($it['title'] ?: 'Untitled', ENT_XML1);
  $link  = htmlspecialchars($it['link'], ENT_XML1);
  $desc  = htmlspecialchars($it['desc'] ?: '', ENT_XML1);
  $date  = date(DATE_RSS, $it['ts']);
  $img   = $it['img'];
  $cat   = htmlspecialchars($it['category'], ENT_XML1);
  $src   = htmlspecialchars($it['source'], ENT_XML1);

  $xml[] = '<item>';
  $xml[] =   "<title>{$title}</title>";
  $xml[] =   "<link>{$link}</link>";
  $xml[] =   "<description>{$desc}</description>";
  $xml[] =   "<pubDate>{$date}</pubDate>";
  $xml[] =   "<guid isPermaLink=\"true\">{$link}</guid>";
  $xml[] =   "<category>{$cat}</category>";
  $xml[] =   "<source>{$src}</source>";
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
echo "✅ feed.xml written with categories\n";