<?php

class NewsHandler
{
private $c;
private $expire;

public Function __construct(array $config)
{
$this->c=$config;
$this->expire=(60*60);
}

public Function newsFeed()
{
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + $this->expire));
Header('Content-Type: application/rss+xml');
$data=@file_get_contents($this->c['news_url']);
die($data);
}

public Function productsFeed()
{
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + $this->expire));
Header('Content-Type: application/rss+xml');
$data=@file_get_contents($this->c['products_url']);
die($data);
}

}

