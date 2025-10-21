<?php
date_default_timezone_set('Asia/Manila');

$SOURCES = [
  'https://rss.app/feeds/f92kwVzjq4XUE6h3.xml', // Gadget News
  'https://rss.app/feeds/jvx17FqERQHCjtka.xml', // PH News
  'https://rss.app/feeds/ICCUIF4kF5MlzXJs.xml', // Tech & Innovation
];

$CHANNEL = [
  'title'       => 'PinoyTechFeed',
  'link'        => 'https://pinoytechfeed.netlify.app',
  'description' => 'Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.',
  'language'    => 'en',
];

function http_get($url, $timeout = 10) {
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

function extract_items($xml, $sourceUrl) {
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

      // Try media:content
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

      // Try <enclosure>
      if (!$img && isset($itm->enclosure)) {
        $attrs = $itm->enclosure->attributes();
        if (isset($attrs['url'])) $img = (string)$attrs['url'];
      }

      // Fallback: first <img> in description
      if (!$img && $desc) {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $desc, $m)) {
          $img = $m[1];
        }
      }

      $out[] = [
        'title' => trim($title),
        'link'  => $link ?: $sourceUrl,
        'desc'  => trim(strip_tags($desc)),
        'ts'    => ts($date),
        'img'   => $img,
      ];
    }
  }
  return $out;
}

$all = [];
foreach ($SOURCES as $src) {
  if (!$xml = http_get($src)) continue;
  foreach (extract_items($xml, $src) as $it) {
    if (!empty($it['link'])) $all[$it['link']] = $it; // de-dupe
  }
}

$items = array_values($all);
usort($items, fn($a,$b) => $b['ts'] <=> $a['ts']);
$items = array_slice($items, 0, 30);

$now  = date(DATE_RSS);
$xml  = [];
$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$xml[] = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">';
$xml[] = '<channel>';
$xml[] = '<title>'.htmlspecialchars($CHANNEL['title'], ENT_XML1).'</title>';
$xml[] = '<link>'.$CHANNEL['link'].'</link>';
$xml[] = '<description>'.htmlspecialchars($CHANNEL['description'], ENT_XML1).'</description>';
$xml[] = '<language>'.$CHANNEL['language'].'</language>';
$xml[] = '<lastBuildDate>'.$now.'</lastBuildDate>';
$xml[] = '<generator>PinoyTechFeed RSS Generator</generator>';

foreach ($items as $it) {
  $title = htmlspecialchars($it['title'] ?: 'Untitled', ENT_XML1);
  $link  = htmlspecialchars($it['link'], ENT_XML1);
  $desc  = htmlspecialchars($it['desc'] ?: '', ENT_XML1);
  $date  = date(DATE_RSS, $it['ts']);
  $img   = $it['img'];

  $xml[] = '<item>';
  $xml[] =   "<title>{$title}</title>";
  $xml[] =   "<link>{$link}</link>";
  $xml[] =   "<description>{$desc}</description>";
  $xml[] =   "<pubDate>{$date}</pubDate>";
  $xml[] =   "<guid isPermaLink=\"true\">{$link}</guid>";

  if ($img) {
    $type = (str_ends_with(strtolower($img), '.png')) ? 'image/png' : 'image/jpeg';
    $xml[] =   "<enclosure url=\"".htmlspecialchars($img, ENT_XML1)."\" type=\"{$type}\" />";
    $xml[] =   "<media:content url=\"".htmlspecialchars($img, ENT_XML1)."\" medium=\"image\" />";
  }

  $xml[] = '</item>';
}

$xml[] = '</channel>';
$xml[] = '</rss>';

file_put_contents(__DIR__.'/feed.xml', implode("\n", $xml));
echo "✅ feed.xml written successfully!\n";
