<?php
/**
 * Request handling helpers
 *
 * @package Utility
 */
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

public function getStrValidate($key, array $valid, $def=null, $defv=null)
{
$s=$this->getStr($key, false);
if ($s===false)
	return $def;

if (!in_array($s, $valid, true))
	return $defv;

return $s;
}

public function getInt($key, $def=null)
{
if (!isset($this->d[$key]))
	return $def;
return filter_var($this->d[$key], FILTER_VALIDATE_INT);
}

}
