<?php

class OrderException extends Exception {}
class OrderNotFoundException extends OrderException {}
class OrderCartIsEmptyException extends OrderException {}

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

public Function orders()
{
if (!$this->l->isAuthenticated())
	return Response::json(401, 'Client is not authenticated');

$r=Flight::request()->query;
$ip=isset($r['page']) ? (int)$r['page'] : 1;
$a=isset($r['amount']) ? (int)$r['amount'] : 100;

if ($ip<1 || $ip>5000 || $a<1 || $a>100) {
	return Response::json(500, 'Invalid page or amount');
}

$status=isset($r['status']) ? filter_var(trim($r['status']), FILTER_SANITIZE_STRING) : 'pending';

$ps=array();
switch ($status) {
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
$sortby['status']='desc';
$sortby['created']='desc';
try {
	$ps=$this->be->index_orders($ip, $a, $fr, $sortby);
} catch (Exception $e) {
	Response::json(500, 'Order data load failed', array('line'=>$e->getLine(), 'error'=>$e->getMessage()));
	slog('OrderFail', '', $e);
	return false;
}

$data=array('page'=>$ip, 'ramount'=>$a, 'amount'=>count($ps), 'orders'=>$ps);
Response::json(200, 'Orders', $data);
}

public Function create()
{
if (!$this->l->isAuthenticated())
	return Response::json(401, 'Client is not authenticated');

$r=Flight::request()->data;
$ps=array();

try {
	$barcodes=$r['product'];
	if (!is_array($barcodes))
		throw new Exception('Invalid products');
	$ps=$this->be->createProductOrderFromRef($barcodes);
} catch (Exception $e) {
	return Response::json(500, 'Order creation failed', array('line'=>$e->getLine(), 'error'=>$e->getMessage()));
}

Response::json(201, 'Orders', $ps);
}

public Function setStatus($oid)
{
if (!$this->l->isAuthenticated())
	return Response::json(401, 'Client is not authenticated');

$r=Flight::request()->data;
$oid=filter_var($oid, FILTER_VALIDATE_INT);
$status=$r["status"];

try {
	$ps=$this->be->set_order_status($oid, $status);
} catch (OrderNotFoundException $e) {
	return Response::json(404, 'Order status update failed', array('line'=>$e->getLine(), 'error'=>$e->getMessage()));
} catch (Exception $e) {
	return Response::json(500, 'Order status update failed', array('line'=>$e->getLine(), 'error'=>$e->getMessage()));
}

Response::json(200, 'Order', $ps);
}

public function cart()
{
if (!$this->l->isAuthenticated())
	return Response::json(401, 'Client is not authenticated');

Response::json(200, 'Cart', $this->be->index_cart());
}

public function clearCart()
{
if (!$this->l->isAuthenticated())
	return Response::json(401, 'Client is not authenticated');

Response::json(200, 'Cart', $this->be->clear_cart());
}

public function addProduct()
{
if (!$this->l->isAuthenticated())
	return Response::json(401, 'Client is not authenticated');

$r=new Request(Flight::request()->data);

$sku=$r->getStr("sku");
$q=$r->getInt("quantity");

slog("Cart", Flight::request()->data);

Response::json(200, 'Cart', $this->be->add_to_cart($sku, $q));
}

public function checkout()
{
if (!$this->l->isAuthenticated())
	return Response::json(401, 'Client is not authenticated');

try {
	$ps=$this->be->checkout_cart();
} catch (OrderNotFoundException $e) {
	return Response::json(404, 'Order status update failed', array('line'=>$e->getLine(), 'error'=>$e->getMessage()));
} catch (Exception $e) {
	return Response::json(500, 'Order status update failed', array('line'=>$e->getLine(), 'error'=>$e->getMessage()));
}

Response::json(200, 'Cart', $ps);
}

} // class
