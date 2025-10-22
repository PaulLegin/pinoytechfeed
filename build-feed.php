<?php
/**
 * build-feed.php — Aggregate 3 sources into feed.xml
 * Also writes ptf:target = /p/<slug>.html for each item (used by build-site.php)
 */
date_default_timezone_set('Asia/Manila');

$SOURCES = [
  ['url'=>'https://rss.app/feeds/f92kwVzjq4XUE6h3.xml','tag'=>'GADGETS','label'=>'GSMArena','category'=>'GADGETS'],
  ['url'=>'https://rss.app/feeds/jvx17FqERQHCjtka.xml','tag'=>'PH','label'=>'GMA News','category'=>'PH'],
  ['url'=>'https://rss.app/feeds/xoLNJ5ZwxVDYaRCl.xml','tag'=>'TECH','label'=>'Interesting Engineering','category'=>'TECH'],
];

$SITE   = getenv('SITE_ORIGIN') ?: 'https://pinoytechfeed.pages.dev'; // change if you move
$FEED   = __DIR__.'/feed.xml';

function http_get($url,$timeout=20){
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>$timeout,
    CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_USERAGENT=>'PinoyTechFeed-RSSFetcher/1.0'
  ]);
  $r=curl_exec($ch); curl_close($ch); return $r?:null;
}
function ts($s){ $t=strtotime((string)$s); return $t?:time(); }
function guess_mime($url){
  $ext=strtolower(pathinfo(parse_url($url,PHP_URL_PATH),PATHINFO_EXTENSION));
  return match($ext){ 'png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp', default=>'image/jpeg' };
}
function slugify($s){
  $s=mb_strtolower(trim($s));
  $s=preg_replace('/[^a-z0-9]+/u','-',$s);
  $s=trim($s,'-');
  return $s?:'post';
}
function post_slug($title,$link){ return slugify($title).'-'.substr(md5($link),0,5); }
function extract_items($xml,$src){
  $out=[]; libxml_use_internal_errors(true);
  $sx=simplexml_load_string($xml); if(!$sx) return $out;
  $ns=$sx->getNamespaces(true);
  foreach(($sx->channel->item ?? []) as $it){
    $title=(string)$it->title;
    $link =(string)$it->link ?: $src['url'];
    $desc =(string)$it->description;
    $date =(string)$it->pubDate;
    $img  ='';
    if(isset($ns['media'])){
      $m=$it->children($ns['media']);
      if(isset($m->content)){ $a=$m->content->attributes(); if(!empty($a['url'])) $img=(string)$a['url']; }
      if(!$img && isset($m->thumbnail)){ $a=$m->thumbnail->attributes(); if(!empty($a['url'])) $img=(string)$a['url']; }
    }
    if(!$img && isset($it->enclosure)){ $a=$it->enclosure->attributes(); if(!empty($a['url'])) $img=(string)$a['url']; }
    if(!$img && $desc && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i',$desc,$mm)) $img=$mm[1];

    $out[$link]=[
      'title'=>trim($title),
      'link'=>$link,
      'desc'=>trim(strip_tags($desc)),
      'ts'  => ts($date),
      'img' => $img,
      'tag' => $src['tag'],
      'source'=>$src['label'],
      'category'=>$src['category'],
      'slug'=> post_slug($title,$link),
    ];
  }
  return $out;
}

/* collect */
$all=[];
foreach($SOURCES as $src){
  if(!$xml=http_get($src['url'])) continue;
  $all += extract_items($xml,$src);
}
$items=array_values($all);
usort($items,fn($a,$b)=>$b['ts']<=>$a['ts']);
$items=array_slice($items,0,40);

/* write rss */
$now=date(DATE_RSS);
$xml=[];
$xml[]='<?xml version="1.0" encoding="UTF-8"?>';
$xml[]='<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:ptf="https://pinoytechfeed/pages/ns">';
$xml[]='<channel>';
$xml[]='<title>PinoyTechFeed</title>';
$xml[]='<link>'.htmlspecialchars($SITE,ENT_XML1).'</link>';
$xml[]='<description>Latest gadget updates and Philippine tech news — curated by PinoyTechFeed.</description>';
$xml[]='<language>en</language>';
$xml[]='<lastBuildDate>'.$now.'</lastBuildDate>';
$xml[]='<ttl>30</ttl>';
$xml[]='<generator>PinoyTechFeed Feed Builder</generator>';

foreach($items as $it){
  $title=htmlspecialchars($it['title']?:'Untitled',ENT_XML1);
  $link =htmlspecialchars($it['link'],ENT_XML1);
  $desc =htmlspecialchars($it['desc']?:'',ENT_XML1);
  $date =date(DATE_RSS,$it['ts']);
  $cat  =htmlspecialchars($it['category'],ENT_XML1);
  $src  =htmlspecialchars($it['source'],ENT_XML1);
  $slug =$it['slug'].'.html';
  $target = htmlspecialchars($SITE.'/p/'.$slug,ENT_XML1);

  $xml[]='<item>';
  $xml[]="  <title>{$title}</title>";
  $xml[]="  <link>{$link}</link>";
  $xml[]="  <description>{$desc}</description>";
  $xml[]="  <pubDate>{$date}</pubDate>";
  $xml[]="  <guid isPermaLink=\"true\">{$link}</guid>";
  $xml[]="  <category>{$cat}</category>";
  $xml[]="  <source>{$src}</source>";
  $xml[]="  <ptf:target>{$target}</ptf:target>";
  if($it['img']){
    $type=guess_mime($it['img']); $safe=htmlspecialchars($it['img'],ENT_XML1);
    $xml[]="  <enclosure url=\"{$safe}\" type=\"{$type}\" />";
    $xml[]="  <media:content url=\"{$safe}\" medium=\"image\" />";
  }
  $xml[]='</item>';
}
$xml[]='</channel></rss>';

file_put_contents($FEED,implode("\n",$xml));
echo "✅ feed.xml written\n";
