<?php
date_default_timezone_set('UTC');

$items = [
  [
    'title' => 'Sample Article – New Tech Launch',
    'link' => 'https://sample-site.com/new-tech-launch',
    'guid' => 'https://sample-site.com/new-tech-launch',
    'pubDate' => date(DATE_RSS),
    'description' => 'An overview of the latest gadget innovations launched this week.',
    'image' => 'https://sample-site.com/images/launch.jpg'
  ]
];

$xml = new SimpleXMLElement('<rss/>');
$xml->addAttribute('version', '2.0');
$xml->addAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
$channel = $xml->addChild('channel');
$channel->addChild('title', 'Pinoy Tech Feed');
$channel->addChild('link', 'https://pinoytechfeed.pages.dev/');
$channel->addChild('description', 'Pinoy tech updates, gadgets, apps, innovation — curated headlines.');
$channel->addChild('language', 'en');
$channel->addChild('lastBuildDate', date(DATE_RSS));
$channel->addChild('ttl', '30');

$atomLink = $channel->addChild('atom:link', '', 'http://www.w3.org/2005/Atom');
$atomLink->addAttribute('href', 'https://pinoytechfeed.pages.dev/feed.xml');
$atomLink->addAttribute('rel', 'self');
$atomLink->addAttribute('type', 'application/rss+xml');

foreach ($items as $i) {
  $item = $channel->addChild('item');
  $item->addChild('title', htmlspecialchars($i['title']));
  $item->addChild('link', htmlspecialchars($i['link']));
  $item->addChild('guid', htmlspecialchars($i['guid']))->addAttribute('isPermaLink', 'true');
  $item->addChild('pubDate', $i['pubDate']);
  $desc = $item->addChild('description');
  $descDom = dom_import_simplexml($desc);
  $owner = $descDom->ownerDocument;
  $descDom->appendChild($owner->createCDATASection($i['description']));
  if (!empty($i['image'])) {
    $enclosure = $item->addChild('enclosure');
    $enclosure->addAttribute('url', $i['image']);
    $enclosure->addAttribute('type', 'image/jpeg');
  }
}

$xmlString = $xml->asXML();
file_put_contents('feed.xml', $xmlString);
echo "Feed generated successfully!\n";
?>
