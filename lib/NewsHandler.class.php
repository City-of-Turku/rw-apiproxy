<?php

class NewsHandler
{
private $expire;

public Function __construct(array $config)
{
$this->c=$config;
$this->expire=(60*60);
}

protected Function headers()
{
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + $this->expire));
header('Content-Type: application/rss+xml');
}

protected Function emptyFeed()
{
echo '<?xml version="1.0" encoding="utf-8" ?>';
echo '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:foaf="http://xmlns.com/foaf/0.1/" xmlns:og="http://ogp.me/ns#" xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#" xmlns:sioc="http://rdfs.org/sioc/ns#" xmlns:sioct="http://rdfs.org/sioc/types#" xmlns:skos="http://www.w3.org/2004/02/skos/core#" xmlns:xsd="http://www.w3.org/2001/XMLSchema#">';
echo '<channel><title></title></channel>';
echo '</rss>';
die();
}

public Function newsFeed()
{
$this->headers();
if ($this->c['news_url']=='')
	$this->emptyNews();
$data=@file_get_contents($this->c['news_url']);
die($data);
}

public Function productsFeed()
{
$this->headers();
if ($this->c['products_url']=='')
	$this->emptyFeed();
$data=@file_get_contents($this->c['products_url']);
die($data);
}

}

