<?php

class Request
{
private $d;

public __construct(array $data)
{
$this->d=$data;
}

public getStr($key, $def=null)
{
if (!isset($this->d[$key]))
	return $def;

return filter_var($this->d[$key], FILTER_SANITIZE_STRING);
}

public getInt($key, $def=null)
{
if (!isset($this->d[$key]))
	return $def;
return filter_var($this->d[$key], FILTER_VALIDATE_INT);
}

}
