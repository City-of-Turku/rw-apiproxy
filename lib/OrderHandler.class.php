<?php

class OrderHandler
{
private $l;
private $drupal;

public function __construct(LoginHandler &$l, &$drupal)
{
$this->l=$l;
$this->drupal=$drupal;
}

public Function orders()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'));

$r=Flight::request()->query;
$ip=$page===false ? (int)$r['page'] : $page;
$a=$limit===false ? (int)$r['amount'] : $limit;

if ($ip<1 || $ip>5000 || $a<1 || $a>50) {
        return Flight::json(Response::data(500, 'Invalid page or amount', 'products'));
}

$ps=array();
try {
	$data=$this->drupal->index_orders($ip, $a, filter);
	// XXX: Re-format
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

} // class
