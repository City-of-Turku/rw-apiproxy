<?php

class Request
{
private $d;

public function __construct($data)
{
$this->d=$data;
}

public function getStr($key, $def=null)
{
if (!isset($this->d[$key]))
	return $def;
return filter_var($this->d[$key], FILTER_SANITIZE_STRING);
}

public function getInt($key, $def=null)
{
if (!isset($this->d[$key]))
	return $def;
return filter_var($this->d[$key], FILTER_VALIDATE_INT);
}

}
