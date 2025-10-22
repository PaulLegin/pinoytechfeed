<?php
/**
 * build-feed.php — Aggregates sources into feed.xml
 * - <link> points to your site page (/p/slug.html)
 * - Original link is preserved in <ptf:target>
 */
date_default_timezone_set('Asia/Manila');

/* =======================
   CONFIG
   ======================= */
$SOURCES = [
  ['url' => 'https://rss.app/feeds/f92kwVzjq4XUE6h3.xml', 'tag' => 'gadgets', 'label' => 'GSMArena',             'category' => 'Gadgets'],
  ['url' => 'https://rss.app/feeds/jvx17FqERQHCjtka.xml', 'tag' => 'ph',      'label' => 'GMA News',            'category' => 'PH News'],
  ['url' => 'https://rss.app/feeds/xoLNJ5ZwxVDYaRCl.xml', 'tag' => 'tech',    'label' => 'InterestingEngineering','category' => 'Tech & Innovation'],
];

function env($k,$d=''){ $v=getenv($k); return $v!==false ? trim($v) : $d; }
$SITE_ORIGIN = rtrim(env('SITE_ORIGIN','https://pinoytechfeed.pages.dev'),'/'); // <-- your site

/* =======================
   Helpers
   ======================= */
function http_get($url,$t=20){
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>$t, CURLOPT_TIMEOUT=>$t,
    CURLOPT_USERAGENT=>'PinoyTechFeedFetcher/1.0'
  ]);
  $b=curl_exec($ch); curl_close($ch); return $b?:null;
}
function ts($s){ $t=strtotime((string)$s); return $t?:time(); }
function guess_mime($url){
  $ext=strtolower(pathinfo(parse_url($url,PHP_URL_PATH),PATHINFO_EXTENSION));
  return $ext==='png'?'image/png':($ext==='gif'?'image/gif':($ext==='webp'?'image/webp':'image/jpeg'));
}
function slugify($s){
  $s=iconv('UTF-8','ASCII//TRANSLIT',$s);
  $s=strtolower(preg_replace('~[^a-z0-9]+~','-',$s));
  return trim($s,'-');
}

function extract_items($xml,$tag,$label,$category){
  $out=[]; libxml_use_internal_errors(true);
  $sx=simplexml_load_string($xml); if(!$sx) return $out;
  $ns=$sx->getNamespaces(true);

  if(isset($sx->channel->item)){
    foreach($sx->channel->item as $itm){
      $title=(string)$itm->title;
      $link =(string)$itm->link;
      $desc =(string)$itm->description;
      $date =(string)$itm->pubDate;
      $img  ='';

      if(!empty($ns['media'])){
        $m=$itm->children($ns['media']);
        if(isset($m->content)){ $a=$m->content->attributes(); if(isset($a['url'])) $img=(string)$a['url']; }
        if(!$img && isset($m->thumbnail)){ $a=$m->thumbnail->attributes(); if(isset($a['url'])) $img=(string)$a['url']; }
      }
      if(!$img && isset($itm->enclosure)){ $a=$itm->enclosure->attributes(); if(isset($a['url'])) $img=(string)$a['url']; }
      if(!$img && $desc && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i',$desc,$m)) $img=$m[1];

      $out[]=[
        'title'=>trim($title?:'(no title)'),
        'source_link'=>$link,
        'desc'=>trim(strip_tags($desc)),
        'ts'=>ts($date),
        'img'=>$img,
        'tag'=>$tag,
        'source'=>$label,
        'category'=>$category,
      ];
    }
  }
  return $out;
}

/* =======================
   Collect & dedupe
   ======================= */
$all=[];
foreach($SOURCES as $s){
  if(!$xml=http_get($s['url'])) continue;
  foreach(extract_items($xml,$s['tag'],$s['label'],$s['category']) as $it){
    if(!empty($it['source_link'])) $all[$it['source_link']]=$it;
  }
}
$items=array_values($all);
usort($items,fn($a,$b)=>$b['ts']<=>$a['ts']);
$items=array_slice($items,0,50);

/* =======================
   Build feed.xml
   ======================= */
$now=date(DATE_RSS);
$xml=[];
$xml[]='<?xml version="1.0" encoding="UTF-8"?>';
$xml[]='<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:ptf="https://pinoytechfeed.pages.dev/ns">';
$xml[]='<channel>';
$xml[]='<atom:link href="'.$SITE_ORIGIN.'/feed.xml" rel="self" type="application/rss+xml"/>';
$xml[]='<title>PinoyTechFeed</title>';
$xml[]='<link>'.$SITE_ORIGIN.'</link>';
$xml[]='<description>Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.</description>';
$xml[]='<language>en</language>';
$xml[]='<lastBuildDate>'.$now.'</lastBuildDate>';
$xml[]='<ttl>30</ttl>';
$xml[]='<generator>PinoyTechFeed RSS Generator</generator>';

foreach($items as $it){
  $slug = slugify($it['title']);
  // Parang id para iwas banggaan kapag pare-pareho ang title
  $slug .= '-'.substr(md5($it['source_link']),0,5);
  $site_link = $SITE_ORIGIN.'/p/'.$slug.'.html';

  $title = htmlspecialchars($it['title'],ENT_XML1);
  $desc  = htmlspecialchars($it['desc'] ?: $it['title'],ENT_XML1);
  $date  = date(DATE_RSS,$it['ts']);
  $cat   = htmlspecialchars($it['category'],ENT_XML1);
  $src   = htmlspecialchars($it['source'],ENT_XML1);
  $img   = $it['img'] ? htmlspecialchars($it['img'],ENT_XML1) : '';
  $mime  = guess_mime($it['img']);
  $orig  = htmlspecialchars($it['source_link'],ENT_XML1);

  $xml[]='<item>';
  $xml[]='  <title>'.$title.'</title>';
  $xml[]='  <link>'.$site_link.'</link>';               // <-- YOUR SITE PAGE
  $xml[]='  <description>'.$desc.'</description>';
  $xml[]='  <pubDate>'.$date.'</pubDate>';
  $xml[]='  <guid isPermaLink="true">'.$site_link.'</guid>';
  $xml[]='  <category>'.$cat.'</category>';
  $xml[]='  <source>'.$src.'</source>';
  $xml[]='  <ptf:target>'.$orig.'</ptf:target>';        // <-- original article
  if($img){
    $safe=$img;
    $xml[]='  <enclosure url="'.$safe.'" type="'.$mime.'"/>';
    $xml[]='  <media:content url="'.$safe.'" medium="image"/>';
  }
  $xml[]='</item>';
}
$xml[]='</channel></rss>';

file_put_contents(__DIR__.'/feed.xml',implode("\n",$xml));
echo "✅ feed.xml written for {$SITE_ORIGIN}\n";