<?php
date_default_timezone_set('Asia/Manila');

$SOURCES = [
  // Replace these with your real RSS feed URLs from RSS.app
  'https://rss.app/feeds/YOUR_GADGET_FEED.xml',
  'https://rss.app/feeds/YOUR_PHNEWS_FEED.xml',
  'https://rss.app/feeds/YOUR_TECH_FEED.xml',
];

$CHANNEL = [
  'title'       => 'PinoyTechFeed',
  'link'        => 'https://pinoytechfeed.netlify.app',
  'description' => 'Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.',
  'language'    => 'en',
];

function http_get($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_USERAGENT => 'PinoyTechFeed-RSSFetcher/1.0'
  ]);
  $body = curl_exec($ch);
  curl_close($ch);
  return $body;
}

function extract_items($xml, $sourceUrl) {
  $items = [];
  $sx = simplexml_load_string($xml);
  if (!$sx) return $items;
  if (isset($sx->channel->item)) {
    foreach ($sx->channel->item as $itm) {
      $items[] = [
        'title' => (string)$itm->title,
        'link' => (string)$itm->link,
        'desc' => strip_tags((string)$itm->description),
        'ts' => strtotime((string)$itm->pubDate)
      ];
    }
  }
  return $items;
}

$all = [];
foreach ($SOURCES as $src) {
  $body = http_get($src);
  if (!$body) continue;
  foreach (extract_items($body, $src) as $it) {
    $all[$it['link']] = $it;
  }
}

usort($all, fn($a, $b) => $b['ts'] <=> $a['ts']);
$now = date(DATE_RSS);

$xml = "<?xml version='1.0' encoding='UTF-8'?>\n<rss version='2.0'><channel>\n";
$xml .= "<title>{$CHANNEL['title']}</title><link>{$CHANNEL['link']}</link><description>{$CHANNEL['description']}</description><language>{$CHANNEL['language']}</language><lastBuildDate>$now</lastBuildDate>\n";

foreach (array_slice($all, 0, 30) as $it) {
  $title = htmlspecialchars($it['title'], ENT_XML1);
  $desc = htmlspecialchars($it['desc'], ENT_XML1);
  $link = htmlspecialchars($it['link'], ENT_XML1);
  $date = date(DATE_RSS, $it['ts']);
  $xml .= "<item><title>$title</title><link>$link</link><description>$desc</description><pubDate>$date</pubDate><guid isPermaLink='true'>$link</guid></item>\n";
}

$xml .= "</channel></rss>";
file_put_contents(__DIR__ . '/feed.xml', $xml);

echo "✅ feed.xml written successfully!";
?>
