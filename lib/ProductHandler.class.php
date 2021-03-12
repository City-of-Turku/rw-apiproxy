<?php

class ProductException extends Exception {}
class ProductErrorException extends ProductException {}
class ProductDataException extends ProductException {}
class ProductImageException extends ProductException {}
class ProductNotFoundException extends ProductException {}
class ProductExistsException extends ProductException {}

/**
 * Handle product related requests.
 *
 * @package Handler
 */
class ProductHandler extends Handler
{
private $api; // XXX should follow others: $be
private $map;
private $catmap;
private $colormap;
private $validSort=array('title_desc','title_asc','date_asc','date_desc','sku','price_asc','price_desc');

private $cmap_cache='rw-colormap-1'; // Bump if format changes

public function __construct(LoginHandler &$l, BackendActionsInterface &$be, array $config=null)
{
$this->l=$l;
$this->api=$be;
$this->c=$config;

// Load product attributes key=>value mappings from json files
//$cmap=json_decode(file_get_contents('colormap.json'), true);
$umap=json_decode(file_get_contents('usagemap.json'), true);

$tmp=$this->getDataFromCache($this->cmap_cache);
if ($tmp) {
	$cmap=json_decode($tmp, true);
	$this->api->setColorMap($cmap);
}

$this->api->setUsageMap($umap);

// This is used here too
$this->catmap=json_decode(file_get_contents('categorymap.json'), true);
$this->api->setCategoryMap($this->catmap);
}

/**
 * upload_file()
 *
 * Helper to request upload of file to Drupal API.
 *
 * Returns: Druapl File ID
 */
protected function upload_file($file, $filename)
{
$r=$this->api->upload_file($file, $filename, true);
return $r->fid;
}

/**
 *
 *
 */
protected Function addImagesFromUpload(array $images, array &$errors)
{
$fids=array();
$c=count($images['name']);
for ($i=0;$i<$c;$i++) {
	if ($images['error'][$i]!=0) {
		$errors[]=$images['name'][$i];
		continue;
	}
	$fids[]=$this->upload_file($images['tmp_name'][$i], $images['name'][$i]);
}
return $fids;
}

private Function dumpImageData($mime, $data)
{
// Cache for 10 minutes, for now.. we might up this as product images won't change afterwards
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
	if (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < time() - 600) {
		header('HTTP/1.1 304 Not Modified');
		die();
	}
}
// Allow caching on client side
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (60 * 60 * 24)));
header("Content-Type: ".$mime);
header("Content-Length: ".strlen($data));
die($data);
}

private function get_image($url, array $opts=null)
{
$c=null;
if (is_array($opts)) {
	$auth=base64_encode($opts['username'].":".$opts['password']);
	$sc=array("http" => array("header" => "Authorization: Basic $auth"));
	$c=stream_context_create($sc);
}
return @file_get_contents($url, false, $sc);
}

private Function getDataFromCache($key)
{
try {
	$r=new Redis();
	$r->connect('127.0.0.1', 6379);
	return $r->get($key);
} catch (Exception $e) {
	slog("RedisGetFail", $key, $e);
}
return false;
}

private Function setDataToCache($key, $data)
{
try {
	$r=new Redis();
	$r->connect('127.0.0.1', 6379);
	return $r->set($key, $data);
} catch (Exception $e) {
	slog("RedisSetFail", $key, $e);
}
}

public Function getProductImage($style, $fid)
{
$style=filter_var($style, FILTER_SANITIZE_STRING);
$fid=filter_var($fid, FILTER_VALIDATE_INT);

// We allow anonymous retrieval of product images, client must still be authenticated with client key
if (!$this->l->isClientAuthenticated())
	return Response::json(401, 'Client is not authenticated');

if (!is_numeric($fid))
	return Response::json(400, 'Invalid image identifier');

$key=sprintf("img-%s-%d", $style, $fid);

$data=$this->getDataFromCache($key);
if ($data!==false)
	$this->dumpImageData('image/jpeg', $data); // Does not return

$opts=null;
try {
	$file=$this->api->view_file($fid, false, true);
} catch (Exception $e) {
	slog('ImageFromAPIFailed', false, $e);
	return Response::json(500, 'Image loading failed');
}

if (!property_exists($file, "image_styles"))
	return Response::json(500, 'Image styles not set');

$styles=$file->image_styles;
if (!is_object($styles))
	return Response::json(500, 'Image styles are invalid');

if (!property_exists($styles, "$style"))
	return Response::json(412, 'Image style not configured');

$data=$this->get_image($styles->$style, $opts);
if ($data===false) {
	slog('ImageFetchFailed', $styles->$style);
	return Response::json(500, 'Image data fetch failed');
}

$this->setDataToCache($key, $data);
$this->dumpImageData('image/jpeg', $data);
}

protected Function dataToProduct(array $d)
{
$p=new Product();

$p->sku=$d['barcode'];
$p->title=$d['title'];
$p->category=$d['category'];
$p->stock=1;

return $p;
}

/**
 * browse();
 *
 * Product browsing and search public API handler
 *
 */
public Function browse($page=1)
{
$this->checkAuth();

$r=Flight::request()->query;
// Show only products that are enabled and in stock
$filter=array(
	'status'=>array(1, '='),
	'stock'=>array(0, '>')
);
$req=new Request($r);

if (isset($r['q']))
	$filter['title']=filter_var(trim($r['q']), FILTER_SANITIZE_STRING);

if (isset($r['category'])) {
	$t=$this->api->categoryMap($r['category']);
	if ($t===false)
		return Response::json(500, 'Invalid category filter');
	$filter['type']=$t;
}

$sb=$req->getStrValidate('s', $this->validSort, 'date_desc', false);

switch ($sb) {
	case 'sku':
	$sortby=array('sku'=>'asc');
	break;
	case 'title_asc':
	$sortby=array('title'=>'asc');
	break;
	case 'title_desc':
	$sortby=array('title'=>'desc');
	break;
	case 'date_asc':
	$sortby=array('created'=>'asc');
	break;
	case 'date_desc':
	$sortby=array('created'=>'desc');
	break;
	default:
		return Response::json(500, 'Invalid sorting option given');
}

$this->browseProducts(false, false, $filter, $sortby);
}

/**
 * browseProducts();
 *
 * Does the actual call to Drupal
 *
 * $page page number, or false to get from request
 * $limit items per page, or false to get from request
 * $filter array of filtering variables
 *
 */
protected Function browseProducts($page=false, $limit=false, array $filter=null, array $sortby=null)
{
$r=Flight::request()->query;
$ip=$page===false ? (int)$r['page'] : $page;
$a=$limit===false ? (int)$r['amount'] : $limit;

if ($ip<1 || $ip>5000 || $a<1 || $a>100) {
	slog('Invalid paging', array($ip, $a));
	return Response::json(500, 'Invalid page or amount');
}

$ps=array();
try {
	$ps=$this->api->index_products($ip, $a, $filter, $sortby);
} catch (Exception $e) {
	slog('browseProduct', false, $e, true);
	Response::json(500, 'Data load failed', array('line'=>$e->getLine(), 'error'=>$e->getMessage()));
	return false;
}

// Special case, search specific barcode
if (count($ps)===0 && $page==1 && $limit==1 && is_array($filter))
	return Response::json(404, 'Product not found');

$data=array('page'=>$ip, 'ramount'=>$a, 'amount'=>count($ps), 'products'=>$ps);
Response::json(200, 'Products', $data);
}

/**
 * getProduct();
 *
 * Load a specific product with barcode
 *
 */
public Function getProduct($barcode)
{
$this->checkAuth();

if (!$this->api->validateBarcode($barcode)) {
	Response::json(500, 'Invalid barcode');
	return false;
}

try {
	$ps=$this->api->index_products(1, 1, array('sku'=>$barcode));
	slog('product', $ps);
} catch (Exception $e) {
	slog('product', $barcode, $e);
	Response::json(404, 'Product not found');
	return false;
}

Response::json(200, 'Product', $ps[0]);
}

protected Function mapVariable($id, $df, array &$o, array &$er)
{
$r=Flight::request()->data;

// Should we just ignore it
if (isset($o['ignore']) && $o['ignore']===true)
	return true;

// First check if it exist or not and if required
if ($o['required']===true && !isset($r[$id])) {
	$er[$id]='Required parameter is missing';
	return false;
} else if ($o['required']===false && !isset($r[$id]) && !isset($o['default'])) {
	return true;
}

// Set default value in case not set
if ($o['required']===false && !isset($r[$id]) && isset($o['default'])) {
	$v=$o['default'];
} else {
	$v=$r[$id];
}

// Check if empty value can be just ignored, or do we need this ?
//if ($o['required']===false &&

$type=$o['type'];
switch ($type) {
	case 'string':
		$v=trim($v);
		if (!is_string($v))
			$er[$id]='Invalid contents, not a string: '.$v;
		if (isset($o['cb_map'])) {
			$v=call_user_func(array($this, $o['cb_map']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, not found in map';
				return false;
			}
		}
		if (isset($o['cb_validate'])) {
			$v=call_user_func(array($this, $o['cb_validate']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, did not validate';
				return false;
			}
		}
	break;
	case 'int':
		if (!is_numeric($v))
			$er[$id]='Invalid contents, not a number '.$v;
		$v=(int)$v;
		if (isset($o['max_value']) && $v>$o['max_value'])
			$er[$id]='Value too large: '.$v;
		else if (isset($o['min_value']) && $v<$o['min_value'])
			$er[$id]='Value too small: '.$v;
		if (isset($o['cb_map'])) {
			$v=call_user_func(array($this, $o['cb_map']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, not found in map';
				return false;
			}
		}
		if (isset($o['cb_validate'])) {
			$v=call_user_func(array($this, $o['cb_validate']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, did not validate';
				return false;
			}
		}
	break;
	case 'nodeid':
		if (!is_numeric($v) || $v<1)
			$er[$id]='Invalid node reference ID';
		$v=(int)$v;
	break;
	default:
		$er[$id]='Unknown type. Invalid contents';
		return false;
}

return $v;
}

protected Function mapRequest(array &$er)
{
foreach ($this->map as $df => $o) {
	$id=$o['id'];

	if (is_array($id)) {
		// Map multiple request variables into one drupal field
		$vididx=0;
		$v=array();
		foreach ($id as $tid => $dv) {
			$fid=$o['vid'][$vididx];
			if (is_null($dv)) {
				$tmp=$this->mapVariable($tid, $df, $o, $er);
				if ($tmp===false || $tmp===true)
					continue;
				$v[$fid]=$tmp;
			} else {
				$v[$fid]=$dv;
			}
			$vididx++;
		}
		if (count($v)==count($id))
			$f[$df]=$v;
	} else {
		// 1:1
		$v=$this->mapVariable($id, $df, $o, $er);
		if ($v===false || $v===true) // Skip or Error case, lets just collection any more errors
			continue;
		if (isset($o['field_id'])) {
			$f[$df][$o['field_id']]=$v;
		} else {
			$f[$df]=$v;
		}
	}
}

return $f;
}

/**
 * add()
 *
 * Add new product to Backend
 */
public Function add()
{
$this->checkAuth();

$fer='';

$data=Flight::request()->data->getData();

try {
	$rf=Flight::request()->files;
	if (count($rf)===0) {
		Response::json(400, 'Missing product images');
		return;
	}
	$r=$this->api->add_product($data, $rf['images'], $fer);
	Response::json(201, 'Product add', array("response"=>$r, "file_errors"=>$fer));
} catch (ProductExistsException $e) {
	slog('SKU already exists', false);
	Response::json(409, 'SKU already exists', array('error'=>$e->getMessage()));
} catch (ProductException $e) {
	slog('Invalid product data', $data, $e);
	Response::json(400, 'Invalid product data', array('error'=>$e->getMessage()));
}

}

public Function update($barcode)
{
$this->checkAuth();

if (!$this->api->validateBarcode($barcode)) {
	Response::json(400, 'Invalid product barcode');
	return false;
}

$data=Flight::request()->data->getData();

try {
	$rf=Flight::request()->files;
	$r=$this->api->update_product($barcode, $data, count($rf)==0 ? null : $rf['images']);
	Response::json(200, 'Product update', array("response"=>$r));
} catch (ProductException $e) {
	$data=array('error'=>$e->getMessage());
	slog('Invalid product data for update', $data, $e);
	Response::json(400, 'Invalid product data for update', $data);
}

}

protected Function get_by_id($pid)
{
return $this->api->get_product($pid);
}

protected Function get_by_sku($sku)
{
return $this->api->get_product_by_sku($sku);
}

public Function stockUpdate()
{
$this->checkAuth();

Response::json(500, 'Not implemented');
}

public Function delete()
{
$this->checkAuth();

Response::json(500, 'Not implemented');
}

public Function categories()
{
$this->checkAuth();

Response::json(200, 'Categories', $this->catmap);
}

public Function colors()
{
$this->checkAuth();

// Check cache
$tmp=$this->getDataFromCache($this->cmap_cache);
if ($tmp!==false) {
	$cmap=json_decode($tmp, true);
	return Response::json(200, 'Colors', $cmap);
}

slog('Colors not in cache, requesting');
$c=$this->api->get_colors();
$this->setDataToCache($this->cmap_cache, json_encode($c));
Response::json(200, 'Colors', $c);
}

}
