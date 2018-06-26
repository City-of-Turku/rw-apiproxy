<?php

class OrderException extends Exception {}

class OrderHandler
{
private $l;
private $be;

public function __construct(LoginHandler &$l, &$be)
{
$this->l=$l;
$this->be=$be;
}

public Function orders($page=1, $limit=10)
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'orders'));

$r=Flight::request()->query;
$ip=$page===false ? (int)$r['page'] : $page;
$a=$limit===false ? (int)$r['amount'] : $limit;

if ($ip<1 || $ip>5000 || $a<1 || $a>50) {
        return Flight::json(Response::data(500, 'Invalid page or amount', 'orders'));
}

$ps=array();
try {
	$data=$this->be->index_orders($ip, $a);
	foreach ($data as $oid => $po) {
		$ps[$oid]=$po;
	}
} catch (Exception $e) {
	Flight::json(Response::data(500, 'Order data load failed', 'order', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
	return false;
}

$data=array('page'=>$ip, 'ramount'=>$a, 'amount'=>count($ps), 'orders'=>$ps);
Flight::json(Response::data(200, 'Orders', 'orders', $data));
}

public Function create()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'create'));

$r=Flight::request()->data;
$ps=array();

try {
	$barcodes=$r['product'];
	if (!is_array($barcodes))
		throw new Exception('Invalid products');
	$ps=$this->be->createProductOrderFromRef($barcodes);
} catch (Exception $e) {
	Flight::json(Response::data(500, 'Order creation failed', 'order', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
	return false;
}

Flight::json(Response::data(201, 'Orders', 'create', $ps));
}

} // class
