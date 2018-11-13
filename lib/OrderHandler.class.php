<?php

class OrderException extends Exception {}
class OrderNotFoundException extends OrderException {}

class OrderHandler
{
private $l;
private $be;
private $validStatus=array("canceled", "pending", "processing", "completed");

public function __construct(LoginHandler &$l, &$be)
{
$this->l=$l;
$this->be=$be;
}

public Function orders($page=1, $limit=10, $otype='pending', $uid=null)
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
switch ($otype) {
	case 'all':
		$fr=null;
	break;
	case 'cart':
		$fr=array('status'=>'cart');
	break;
	case 'pending':
		$fr=array('status'=>'pending');
	break;
	case 'processing':
		$fr=array('status'=>'processing');
	break;
	case 'completed':
		$fr=array('status'=>'completed');
	break;
	default:
		$fr=null;
}
$sortby['created']='desc';
try {
	$ps=$this->be->index_orders($ip, $a, $fr, $sortby);
	slog('Order', $ps);
} catch (Exception $e) {
	Flight::json(Response::data(500, 'Order data load failed', 'order', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
	slog('OrderFail', '', $e);
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
	return Flight::json(Response::data(500, 'Order creation failed', 'order', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
}

Flight::json(Response::data(201, 'Orders', 'create', $ps));
}

public Function setStatus($oid)
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'), 401);

$r=Flight::request()->data;
$oid=filter_var($oid, FILTER_VALIDATE_INT);
$status=$r["status"];

try {
	$ps=$this->be->set_order_status($oid, $status);
} catch (OrderNotFoundException $e) {
	return Flight::json(Response::data(404, 'Order status update failed', 'order', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 404);
} catch (Exception $e) {
	return Flight::json(Response::data(500, 'Order status update failed', 'order', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
}

Flight::json(Response::data(200, 'Order', 'status', $ps));
}

} // class
