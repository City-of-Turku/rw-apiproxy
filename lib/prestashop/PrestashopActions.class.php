<?php
require_once('PSWebServiceLibrary.php');

class PrestashopActions extends BackendActionsInterface
{
protected $pws;
protected $err;
protected $products;
protected $product_stock_map;
protected $shop_id;
protected $db;
protected $salt;
protected $pwdsalt;

protected $presta_base_url='';
protected $api_base_url='';

// XXX
protected $default_cid=12;
protected $default_tax=1;
protected $source_id=0; // Supplier or manufacturer
protected $cmap;
protected $source;

protected $username;
protected $password;

private $hmac_key;
private $decrypt_key;

// What are we using
const SRC_SUPPLIER=1;
const SRC_MANUFACTURER=2;
protected $append_to_existing=true;
protected $warehouse_location='';

// Defaults for carts & orders
protected $id_lang=1;
protected $id_carrier=1;
protected $id_customer=1;
protected $id_address=1;
protected $id_currency=1;

protected $debug=false;

protected $img_style='large_default';

public Function __construct(array $api_config, array $config)
{
$this->pws=new PrestaShopWebservice($config['url'], $config['key'], $this->debug);
$this->products=array();
$this->product_stock_map=array();
$this->source=self::SRC_SUPPLIER;
$this->api_base_url=$api_config['api_base_url'];
$this->presta_base_url=$config['url'];
$this->pwdsalt=$config['presta_salt'];

$this->aes=new AES($this->decrypt_key, $this->hmac_key);

// Load client categorykey => (presta,id,map)
$this->cmap=json_decode(file_get_contents($config['categorymap']), true);;

$this->db=new mysqli($config['host'], $config['user'], $config['password'], $config['db']);
if ($this->db->connect_error)
	throw new Exception('DB Failure '.$this->db->connect_error, $this->db->connect_errno);
}

public function set_auth($username, $password)
{
if (!is_string($username))
	throw new Exception('Invalid username', 500);
if (!is_string($password))
	throw new Exception('Invalid password', 500);
$this->username=$username;
$this->password=$password;
}

public function get_user()
{
}

public function login()
{
//if ($this->username!='test' || $this->password!='test')
//	throw new Exception('Authentication error', 403);

if (!filter_var($this->username, FILTER_VALIDATE_EMAIL))
	throw new Exception('Authentication error (0)', 403);

$cpwd=md5($this->pwdsalt.$this->password);
$s=$this->db->prepare("select id_employee,lastname,firstname,email FROM ps_employee WHERE active=1 AND email=? AND passwd=?");
if (!$s)
	throw new Exception('Authentication error (1)'.$this->db->error, 403);

$s->bind_param("ss", $this->username, $cpwd);

if (!$s->execute())
	throw new Exception('Authentication error (2)', 403);

$r=$this->db->store_result();

$o=$r->fetch_object();
if (!$o)
	throw new Exception('Authentication error (4)', 403);

$r->close();

$u=array();
$u['username']=$this->username;
$u['uid']=$o->id_employee;
$u['email']=$this->username;

$tmp=array(
 'u'=>$u['uid'],
 'e'=>$this->username
);
$u['apitoken']=json_encode($tmp);

return $u;
}

public function check_auth()
{
if (empty($this->token))
	return false;

$r=$this->aes->decrypt($this->token);
if (empty($r))
	return false;
$u=@json_decode($r);
if (!$u)
	return false;

// XXX Add proper check

return true;
}

public function logout()
{
return true;
}

public function auth_apikey($key)
{
// return true;
return $this->checkApiKey($key);
}

// Locations
public function get_locations()
{
$loc=array();

$def=new stdClass;
$def->id=1;
$def->location='Default';
$def->zipcode='00000';
$def->street='';
$def->city='';

$loc[$def->id]=$def;

return $loc;
}

// Categories
public function get_categories()
{
return array();
}

// Files (images)
public function upload_file($file, $filename=null)
{
}

public function view_file($fid, $data=false)
{
$i=new stdClass;
$s=new stdClass;
$s->large_default=sprintf('%s/%d-%s/prestashop_image.jpg', $this->presta_base_url, $fid, $this->img_style);
$i->image_styles=$s;
return $i;
}

public function add_product($data, array $files)
{
$p=new Product();
$p->sku=array($data['barcode']);
$p->description=$data['description'];
$p->name=$data['title'];
$p->images=$files;
// Subcategory is more specific so use it if set
$p->category=empty($data['subcategory']) ? $data['category'] : $data['subcategory'];
if (isset($data['stock']) && is_numeric($data['stock']) && (int)$data['stock']>0)
	$p->quantity=(int)$data['stock'];

if (!empty($data['ean'])) {
	$p->ean13=$data['ean'];
} else if (!empty($data['isbn'])) {
	$p->ean13=$data['isbn'];
}

if (!empty($data['price']))
	$p->price=$data['price'];
if (!empty($data['tax']))
	$p->tax=$data['tax'];

if (!empty($data['weight']) && is_numeric($data['weight']) && $data['weight']>0)
	$p->weight=$data['weight'];

slog("Data", $data);
slog("Product", $p);

return $this->addProduct($p);
}

// Products
public function create_product($type, $sku, $title, $price)
{
}

protected function xmlToProduct()
{
}

public function index_products($page=0, $pagesize=20, array $filter=null, array $sortby=null)
{
$ps=array();
$xml=$this->getProducts($page, $pagesize, $filter, $sortby);
if (!$xml)
	return false;

$xps=$xml->children()->children();

foreach ($xps as $ptmp) {
	$a=$ptmp->attributes();
	$pxml=$this->getProduct($a->id);
	if (!$pxml) {
		slog("Failed to fetch product data");
		continue;
	}
	$p=$pxml->children()->children();
	$po=new stdClass;

	$po->id=$a->id;
	$po->barcode=(string)$p->reference;
	$po->ean=(string)$p->ean13;
	$po->title=(string)$p->name[0];
	$po->stock=(int)$p->quantity;
	$po->price=(float)$p->price;
	$po->purpose=0;
	$po->location=0;
	$po->category=''; // XXX
	$po->subcategory=''; // XXX
	$po->description=(string)$p->description[0];
	$img=array();
	foreach ($p->associations->images[0] as $il) {
		// Ok, we cheat here a bit and hope it works. Prestashop API gives images in a stupid way
		// instead of direct urls, it gives url that go trough the api, needing auth and product id and all we need is image identifier
		// we cheat by taking the last part of the url (the actual image id) and use that as the image id
		// xlink:href api/images/products/14/27 <- that
		//
		$iurl=(string)$il->attributes('xlink', true)->href;
		$img[]=sprintf('%s/product/image/%s/%d', $this->api_base_url, $this->img_style, (int)basename($iurl));
	}
	$po->images=$img;
	//$po->thumbnail=(string)$p->id_default_image[0]->attributes('xlink', true)->href;
	if (count($img)>0)
		$po->thumbnail=$img[0];
	
	// print_r($po);die();

	$ps[$po->barcode]=$po;
}
//die();
return $ps;
}

public function get_product($id)
{
}

public function get_product_by_sku($sku)
{
$p=$this->getProductIdByRef($sku);
}

//////////////////////////////////////////////////////////////////////////////

public Function getShopId()
{
if (empty($this->shop_id))
	throw new Exception('Shop is not yet known');

return $this->shop_id;
}

public Function setSource($sid)
{
$this->source=$sid;
}

public Function setDefaultCategoryId($cid)
{
$this->default_cid=$cid;
}

protected Function validate_key($key, $len)
{
if (!is_string($key))
	return false;
if (strlen($key)!=$len)
	return false;
if (!ctype_xdigit($key))
	return false;
return true;
}

protected Function checkApiKey($key)
{
if ($this->validate_key($key, 32)===false)
	return false;
switch ($this->source) {
	case self::SRC_SUPPLIER:
		return $this->checkSupplierApiKey($key);
	break;
	case self::SRC_MANUFACTURER:
		return $this->checkManufacturerApiKey($key);
	break;
}
return false;
}

protected Function checkSupplierApiKey($key)
{
$r=false;
$sql=sprintf("select apikey,id_shop,pss.id_supplier as id_supplier from ps_supplier as pss inner join ps_supplier_shop as psss where pss.id_supplier=psss.id_supplier and pss.active=1 and apikey='%s'", $key);
if ($res=$this->db->query($sql)) {
	if ($res->num_rows>0) {
		$o=$res->fetch_object();
		$this->shop_id=$o->id_shop;
		$this->source_id=$o->id_supplier;
		$r=true;
	}
	$res->close();
}
return $r;
}

protected Function checkManufacturerApiKey($key)
{
$r=false;
$sql=sprintf("select apikey,id_shop,m.id_manufacturer,a.address2 from
	ps_manufacturer as m,
	ps_manufacturer_shop as ms,
	ps_address as a
	where m.id_manufacturer=ms.id_manufacturer and m.id_manufacturer=a.id_manufacturer and m.active=1 and a.deleted=0 and apikey='%s'", $key);
if ($res=$this->db->query($sql)) {
	if ($res->num_rows>0) {
		$o=$res->fetch_object();
		$this->shop_id=$o->id_shop;
		$this->source_id=$o->id_manufacturer;
		$this->warehouse_location=$o->address2;
		$r=true;
	}
	$res->close();
}
return $r;
}

/**
 * Create a new apikey from given serial, apikey is md5 of salt+serial
 * Only active serials are allowed, others will not be accepted.
 */
public Function addApikeyFromSerial($serial)
{
if ($this->validate_key($serial, 16)===false)
	return false;
switch ($this->source) {
	case self::SRC_SUPPLIER:
		return $this->addSupplierApikeyFromSerial($serial);
	break;
	case self::SRC_MANUFACTURER:
		return $this->addManufacturerApikeyFromSerial($serial);
	break;
}
throw new Exception('Invalid source');
}

protected Function addSupplierApikeyFromSerial($serial)
{
$key=$this->createApiKeyFromSerial($serial);
$sql=sprintf("update ps_supplier set apikey='%s' where apikey is null and name='%s' and active=1", $key, $serial);
if ($this->db->query($sql)) {
	if ($this->db->affected_rows==1)
		return $key;
}
return false;
}

protected Function addManufacturerApikeyFromSerial($serial)
{
$key=$this->createApiKeyFromSerial($serial);
$sql=sprintf("update ps_address set apikey='%s' where apikey is null and deleted=0 and other='%s'", $key, $serial);
slog("addManufacturerApikeyFromSerial", $sql);
if ($this->db->query($sql)) {
	slog("addManufacturerApikeyFromSerial", $this->db->affected_rows);
	if ($this->db->affected_rows==1)
		return $key;
}
return false;
}

public Function getSupplierShopFromApikey($key)
{
if ($this->validate_key($key, 32)===false)
	return false;
$sql=sprintf("select id_shop from ps_supplier as pss inner join ps_supplier_shop as psss where pss.id_supplier=psss.id_supplier and pss.active=1 and apikey='%s'", $key);
if ($res=$this->db->query($sql)) {
	if ($res->num_rows>0) {
		$o=$res->fetch_object();
		$this->shop_id=$o->id_shop;
	}
	$res->close();
}
return $this->shop_id ? true : false;
}

public Function setSupplierIdFromApikey($key)
{
if ($this->validate_key($key, 32)===false)
	return false;
$sql=sprintf("select id_supplier from ps_supplier as pss where pss.active=1 and apikey='%s'", $key);
$this->source_id=0;
if ($res=$this->db->query($sql)) {
	if ($res->num_rows>0) {
		$o=$res->fetch_object();
		$this->source_id=$o->id_supplier;
	}
	$res->close();
}
return $this->source_id>0 ? true : false;
}

protected Function getIdFromXML($xml)
{
$resources = $xml->children()->children();
return (int)$resources->id;
}

protected Function createApiKeyFromSerial($serial)
{
return md5($this->salt.$serial);
}

public Function getLastError()
{
return $this->err;
}

public Function setShopId($sid)
{
$this->shop_id=$sid;
}

protected Function cacheProductData($xml)
{
}

protected function getProducts($page=0, $pagesize=20, array $filter=null, array $sortby=null)
{
try {
	$opt=array('resource' => 'products');
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	$opt['limit']=sprintf('%d,%d', ($page-1)*$pagesize, $pagesize);
	// XXX: Handle filter, sortby
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getProducts", $this->err);
}
return false;
}

public Function getProductByRef($reference)
{
try {
	$opt=array('resource' => 'products');
	$opt['filter']['reference']=$reference;
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getProductByRef", $this->err);
}
return false;
}

private Function getProductIdFromResource($xml)
{
$resources=$xml->children()->children();
$c=count($resources);
slog("getProductIdFromResource found product(s): ", $c);
if ($c==0)
	return false;

$c=$resources->product->attributes();
$id=(int)$c->id[0];

return $id;
}

private Function getProductIdByRef($reference)
{
$xml=$this->getProductByRef($reference);
if ($xml===false)
	return false;

$resources=$xml->children()->children();
$c=count($resources);
slog("getProductIdByRef $reference found product(s): ", $c);
if ($c==0)
	return false;

$c=$resources->product->attributes();
$id=(int)$c->id[0];

slog("getProductIdByRef found product id: ", $id);
return $id;
}

protected Function getSpecificPriceByProduct($pid)
{
try {
	$opt=array('resource' => 'specific_prices');
	$opt['filter']['id_product']=(int)$pid;
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getSpecificPriceByProduct", $this->err);
}
return false;
}

protected Function setSpecificPrice($pid, $percent) {
// XXX: Add
}

protected Function removeSpecificPrice($id)
{
try {
	$opt=array('resource' => 'specific_prices','id'=>$id);
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	return $this->pws->delete($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("removeSpecificPrice", $ex->getMessage());
}
return false;
}

/**
 * removeProductSpecificPriceByProductID:
 *
 * Remove any sale price for product.
 *
 * Returns: false if not sale price was set or failure, true if sale was set and removed
 */
public Function removeProductSpecificPriceByProductID($pid)
{
$xml=$this->getSpecificPriceByProduct($pid);
$resources=$xml->children()->children();

if (count($resources)==0) {
	slog("removeProductSpecificPriceByProductID not found", $pid);
	return false;
}

$c=$resources->specific_price->attributes();
$id=(int)$c->id[0];
if ($id===false) {
	slog("removeProductSpecificPriceByProductID no specific price is set", $pid);
	return false;
}

return $this->removeSpecificPrice($id);
}

public Function removeProductSpecificPriceByRef($ref)
{
$id=$this->getProductIdByRef($ref);
if ($id===false)
	return false;
return $this->removeProductSpecificPriceByProductID($id);
}

public Function setProductWarehouseByRef($ref, $warehouse, $location='')
{
$id=$this->getProductIdByRef($ref);
if ($id===false)
	return false;
return $this->setProductWarehouse($id, $warehouse, $location);
}

public Function setProductWarehouse($id, $warehouse, $location)
{
$wid=$this->getWarehouseId();
if ($wid===false)
	return false;

return ($warehouse===true) ? $this->warehousePut($id, $wid, $location) : $this->warehouseRemove($id);
}

// Only the first returned warehouse is used!
protected Function getWarehouseId()
{
try {
	$opt=array('resource' => 'warehouses');
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	$xml=$this->pws->get($opt);
	$resources=$xml->children()->children();
	$c=count($resources);
	slog("Warehouses found: ", $c);
	if ($c==0)
        	return false;

	$c=$resources->warehouse->attributes();
	$id=(int)$c->id[0];
	slog("Warehouse id is: ", $id);
	return $id;
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getWarehouses", $this->err);
}
return false;
}

/**
 * Get the warehouse ID where the given product (id) is stored
 */
protected Function getProductWarehouse($pid)
{
try {
	$opt = array('resource' => 'warehouse_product_locations');
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	$opt['filter']['id_product']=$pid;
	$xml = $this->pws->get($opt);
	$resources = $xml->children()->children();
	$c=$resources->warehouse_product_location->attributes();
	$id=(int)$c->id[0];
	slog("ProductWarehouse for product $pid is $id");
	return $id;
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("warehousePut failed to get product synopsis", $ex->getMessage());
}
return false;
}

protected Function warehouseRemove($pid)
{
$id=$this->getProductWarehouse($pid);
if ($id===false)
	return false;
try {
	$opt=array('resource' => 'warehouse_product_locations','id'=>$id);
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	return $this->pws->delete($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("warehouseRemove failed to get product synopsis", $ex->getMessage());
}
return false;
}

protected Function warehousePut($pid, $wid, $location='')
{
try {
	$opt=array('resource' => 'warehouse_product_locations', 'schema'=>'synopsis');
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	$xml=$this->pws->get($opt);
	$resources=$xml->children()->children();
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("warehousePut failed to get product synopsis", $ex->getMessage());
	return false;
}

$resources->id_product=$pid;
$resources->id_warehouse=$wid;
$resources->id_product_attribute='0';
$resources->location=$location;

try {
	$opt=array('resource' => 'warehouse_product_locations', 'postXml'=>$xml->asXML());
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;	
	return $this->pws->add($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("warehousePut", $this->err);
}
return false;
}

public Function setProductStockByRef($ref, $stock, $op)
{
$id=$this->getProductIdByRef($ref);
if ($id===false)
	return false;
return $this->setProductStock($id, $stock, $op);
}

protected Function getStockAvailable($id)
{
try {
	$opt=array('resource' => 'stock_availables', 'id'=>$id);
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("setProductStock get res", $this->err);
}
return false;
}

protected Function setStockAvailable($id, $xml)
{
// and store back
try {
	$opt=array('resource' => 'stock_availables', 'id'=>$id, 'putXml'=>$xml->asXML());
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	return $this->pws->edit($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("setProductStock update stocks", $this->err);
}
return false;
}

protected Function getProductStockCount($id)
{
}

/**
 * setProductStock:
 * id: barcode
 * stock: numeric stock value to adjust with using
 * op: given stock operation (set,add,rem,dec,inc,clr)
 *
 * Returns: true if operation was succesfull, false in case of any error or wrong parameters
 */
public Function setProductStock($id, $stock, $op)
{
if (!is_numeric($stock))
	return false;
if ($op=='set' && $stock<0)
	return false;

$xml=$this->getProduct($id);
if ($xml===false)
	return false;
$resources=$xml->children()->children();
$sid=$resources->associations->stock_availables->stock_available[0]->id;

slog("setProductStock got stock id", $sid);

// Get the data
$xml=$this->getStockAvailable($sid);
if ($xml===false)
	return false;
$resources=$xml->children()->children();

slog("getStockAvailable returned quantity: ", $resources->quantity);

// Modify it
switch ($op) {
case 'set':
	$resources->quantity=$stock;
break;
case 'add':
	$resources->quantity+=$stock;
break;
case 'inc':
	$resources->quantity++;
break;
case 'dec':
	if ($resource->quantity==0)
		return false;
	$resources->quantity--;
break;
case 'rem':
	// Don't allow negative quantities to be stored
	if ($resources->quantity==0)
		return false;
	$resources->quantity-=$stock;
	if ($resources->quantity<0)
		return false;
break;
case 'clr':
	$resources->quantity=0;
break;
default:
	throw new Exception('Invalid stock operation');
break;
}

slog("Storing new product $id stock ( $sid ) quantity", $resources->quantity);
return $this->setStockAvailable($sid, $xml);
}

/**
 * Request an empty cart structure from API
 */
private Function getEmptyCart()
{
$opt=array('resource' => 'carts', 'schema'=>'blank');
try {
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getEmptyCart failed", $ex->getMessage());
}
return false;
}

private Function getEmptyOrder()
{
$opt=array('resource' => 'orders', 'schema'=>'blank');
try {
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getEmptyOrder failed", $ex->getMessage());
}
return false;
}

private Function storeCart($xml)
{
$opt=array(
	'resource' => 'carts',
	'postXml' => $xml->asXML()
);

// Set shop if requested
if (is_numeric($this->shop_id) || $this->shop_id=='all')
	$opt['id_shop']=$this->shop_id;
try {

	return $this->pws->add($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("createCart add failed", $this->err);
	throw new Exception('Failed to create cart');
}
return false;
}

private Function storeOrder($xml)
{
$opt=array(
	'resource' => 'orders',
	'postXml' => $xml->asXML()
);
// Set shop if requested
if (is_numeric($this->shop_id) || $this->shop_id=='all')
	$opt['id_shop']=$this->shop_id;
try {
	return $this->pws->add($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("createOrder add failed", $this->err);
	//print_r($this->err);
	//print_r($opt);
	throw new Exception('Failed to create order');
}
return false;
}

private Function storeOrderHistory($xml)
{
$opt=array(
	'resource' => 'order_histories',
	'postXml' => $xml->asXML()
);

// Set shop if requested
if (is_numeric($this->shop_id) || $this->shop_id=='all')
	$opt['id_shop']=$this->shop_id;
try {
	return $this->pws->add($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("storeOrderHistory add failed", $this->err);
	throw new Exception('Failed to update order history');
}
return false;
}

private Function getPriceWithTax($tax_group, $price)
{

}

/**
 * Create a shopping cart with the products by barcodes
 *
 */
public Function createProductOrderFromRef(array $barcodes)
{
$xml=$this->getEmptyCart();
if ($xml===false)
	throw new Exception('Failed to get cart');

$products=array();
$quantity=array();
$total=0;
$total_tax=0;

$resources = $xml->children()->children();

// Request the product information from API and store them
foreach ($barcodes as $bc => $cnt) {
	$pres=$this->getProductByRef($bc);
	if ($pres==false)
		throw new Exception('Product not found');
	$id=$this->getProductIdFromResource($pres);
	if ($id==false) {
		// print_r($pres->asXML());
		throw new Exception('Product reference is invalid');
	}
	$pxml=$this->getProduct($id);
	if ($pxml==false)
		throw new Exception('Product load failed');

	$sr=$pxml->children()->children();
	$stock_id=$sr->associations->stock_availables->stock_available[0]->id;

	// Get the data
	$sxml=$this->getStockAvailable($stock_id);
	if ($sxml===false)
		throw new Exception('Product load failed');
	$stock_res=$sxml->children()->children();

	if ($stock_res->quantity < $cnt)
		throw new Exception('Product is sold out');

	$products[$id]=$pxml;
	$quantity[$id]=$cnt;
	$total+=$pres->price*$cnt; // XXX
}

//throw new Exception('Test fail');

slog("Creating cart with products", count($products));

// Fill in the cart with required information and the requested products
$resources->id_currency=$this->id_currency;
$resources->id_lang=$this->id_lang; // XXX
if (is_numeric($this->shop_id))
	$resources->id_shop=$this->shop_id;

$resources->id_customer=$this->id_customer; // XXX

$resources->id_address_delivery=$this->id_address;
$resources->id_address_invoice=$this->id_address;

foreach ($products as $pid => $product) {
	$a=$resources->associations->cart_rows->addChild('cart_row');
	$a->addChild('id_product', $pid);
	$a->addChild('quantity', $quantity[$pid]);
}

// Store the cart
$xml=$this->storeCart($xml);
$resources=$xml->children()->children();
$cart_id=$xml->cart->id;

slog("Cart $cart_id created", $this->err);

slog("Getting order template");
$x=$this->getEmptyOrder();

slog("Order template OK");

$x->order->id_cart=$cart_id;
$x->order->id_customer=$this->id_customer;
$x->order->id_address_delivery=$this->id_address;
$x->order->id_address_invoice=$this->id_address;
$x->order->id_currency=$this->id_currency;
$x->order->id_lang=$this->id_lang;

//$x->order->current_state = 4; // XXX don't set this
$x->order->valid = 0; // XXX
$x->order->id_carrier = $this->id_carrier; // XXX
$x->order->payment = 'Cash on delivery';
$x->order->module = 'cashondelivery';
$x->order->reference='POS';
$x->order->total_paid = $total;
$x->order->total_paid_tax_incl = $total;
$x->order->total_paid_tax_excl = $total;
$x->order->total_paid_real = '0';
$x->order->total_products = $total;
$x->order->total_products_wt = $total;
$x->order->conversion_rate = '1';

$now=date("Y-m-d H:i:s");
$x->order->date_add=$now;
$x->order->delivery_date=$now;

// Add products, again but to the order. Idiotic...
foreach ($products as $pid => $product) {
	$a=$x->order->associations->order_rows->addChild('order_row');
	$a->addChild('product_id', $pid);
	$a->addChild('product_quantity', $quantity[$pid]);
	slog("Adding product $pid to order from cart $cart_id");
	// Do we need to add more ? reference and such ?
}

slog("Storing order");
$xmlo=$this->storeOrder($x);
if ($xmlo===false)
	throw new Exception('Order creation failed');

$order_id=$xmlo->order->id;

// Order is now places, next update payment history
$this->setOrderState($order_id, 5); // Delivered

return $order_id;
}

private Function getEmptyOrderHistory()
{
try {
	$opt = array('resource' => 'order_histories', 'schema'=>'blank');
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getEmptyOrderHistory failed", $ex->getMessage());
}
return false;
}

private Function setOrderState($order_id, $order_status)
{
$xml=$this->getEmptyOrderHistory();
$xml->order_history->id_order=$order_id;
$xml->order_history->id_order_state=$order_status;
return $this->storeOrderHistory($xml);
}

public Function getProduct($id)
{
try {
	$opt=array('resource' => 'products', 'id'=>$id);
	if (is_numeric($this->shop_id))
		$opt['id_shop']=$this->shop_id;
	return $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getProduct", $this->err);
	return false;
}
}

public Function refreshProduct($refold, $refnew)
{
$id=$this->getProductIdByRef($refold);
if ($id===false)
	throw new Exception('Product not found');

$nid=$this->getProductIdByRef($refnew);
if ($nid!==false)
	throw new Exception('Product ref already in use');

$xml=$this->getProduct($id);
if ($xml==false)
	throw new Exception('Product load failed');

$resources=$xml->children()->children();

unset($resources->manufacturer_name);
unset($resources->position_in_category);
unset($resources->quantity);

//$link='sku_'.strtolower(str_replace(' ', '_', $refnew));
//foreach ($resources->link_rewrite->language as $i => $tmp)
//	$resources->link_rewrite->language[$i] = $link;
$resources->reference=$refnew;
$resources->active = '1';
$resources->date_add = date("Y-m-d H:i:s");

$opt=array(
	'resource' => 'products',
	'id'=> $id,
	'putXml' => $xml->asXML()
);

// Set shop if requested
if (is_numeric($this->shop_id) || $this->shop_id=='all')
	$opt['id_shop']=$this->shop_id;

try {
	$xml=$this->pws->edit($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("refresh failed", $this->err);
	return false;
}

// XXX
slog("refresh sale removal for id", $id);
$r=$this->removeProductSpecificPriceByProductID($id);
if ($r==false)
	slog("refresh sale removal not sale set");

return true;
}

private Function getEmptyProduct()
{
try {
	// get base
	$opt = array('resource' => 'products', 'schema'=>'synopsis');
	$xml = $this->pws->get($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("getEmptyProduct failed to get product synopsis", $ex->getMessage());
	return false;
}
return $xml;
}

public Function addProduct(Product $p)
{
$xml=$this->getEmptyProduct();
if ($xml===false)
	return false;

$resources=$xml->children()->children();

// Clear what we don't need
unset($resources->id);
unset($resources->manufacturer_name);
unset($resources->position_in_category);
unset($resources->quantity);

// print_r($resources);die();

// Set what we can directly
// This we can't
//$resources->quantity=1;
$resources->active = '1';

$tax=($p->tax===false) ? $this->default_tax : $p->tax;

$resources->id_tax_rules_group=$tax;
//$resources->id_tax_rules_group=1;

// XXX: Hmm, we could ask but, meh...
switch ($tax) {
	case 0: // ALV 0
		$resources->price = $p->price>0 ? $p->price : 0;
	break;
	case 1: // 24%
		$resources->price = $p->price>0 ? sprintf('%F', $p->price/1.24) : 0; // 24% alv
	break;
	case 2: // 14%
		$resources->price = $p->price>0 ? sprintf('%F', $p->price/1.14) : 0; // 14% alv
	break;
	case 3: // 10%
		$resources->price = $p->price>0 ? sprintf('%F', $p->price/1.10) : 0; // 10% alv
	break;
	default:
		slog("Unknown TAX ID");
		return false;
}

$resources->show_price=($resources->price>0) ? true : false;

$langs=count($resources->name->language);

// Set default product name/title
for ($l=0;$l<$langs;$l++)
	$resources->name->language[$l] = $p->name!=='' ? $p->name : 'Unknown product';

// Set default product description(s)
for ($l=0;$l<$langs;$l++) {
	// Note: Disabled and not used for now
	// $resources->description->language[$l] = $p->description!=='' ? $p->description : '';
	$resources->description_short->language[$l] = $p->description!=='' ? $p->description : '';
}

// XXX
$resources->condition = 'used';

// Default category
$resources->associations->categories->addChild('category')->addChild('id', $this->default_cid);
$resources->id_category_default=$this->default_cid;

// And add user provided category if set
$c=$p->category;
if (isset($this->cmap[$c])) {
	slog("Assigning category key", $c);
	// $cid=explode(',', $this->cmap[$c]);
	foreach ($this->cmap[$c] as $ccid) {
		slog("Category ID", $ccid);
		$resources->associations->categories->addChild('category')->addChild('id', $ccid);
	}
} else {
	slog("Category mapping not found for key", $c);
}

switch ($this->source) {
	case self::SRC_SUPPLIER:
		if ($this->source_id>0)
			$resources->id_supplier=$this->source_id;
	break;
	case self::SRC_MANUFACTURER:
		if ($this->source_id>0)
			$resources->id_manufacturer=$this->source_id;
	break;
}

foreach ($p->sku as $sku) {
	// Check if product SKU exists and if it does just append images to it
	$pid=$this->getProductIdByRef($sku);
	if ($pid!==false && $pid>0) {
		slog("addProduct found existing SKU, appending images to id $pid", $sku);
		$this->addProductImagesFromUpload($pid, $p->images);
		continue;
	}

	// SKU didn't exist so add it now with default options and uploaded images

	$link='sku_'.strtolower(str_replace(' ', '_', $sku));
        $resources->link_rewrite->language[1] = $link;
	$resources->reference=$sku;

       	$opt=array(
		'resource' => 'products',
		'postXml' => $xml->asXML()
	);

	// Set shop if requested
	if (is_numeric($this->shop_id) || $this->shop_id=='all')
		$opt['id_shop']=$this->shop_id;

	try {
		$xml=$this->pws->add($opt);
		slog("addProduct", $xml);
	} catch (PrestaShopWebserviceException $ex) {
		$this->err=$ex->getMessage();
		slog("addProduct add failed", $this->err);
		return false;
	}

	$resources=$xml->children()->children();
	$id=$this->getIdFromXML($xml);

	$pmsg=sprintf("SKU %s ID %s Price %d", $sku, $id, $p->price);
	slog("addProduct", $pmsg);

	// Store the product info we just added and cache the products stock id
	$this->products[$id]=$xml;
	$this->product_stock_map[$id]=$resources->associations->stock_availables->stock_available[0]->id;

	// Add the images
	if (is_array($p->images)) {
		$this->addProductImagesFromUpload($id, $p->images);
	}

	// Set product stock
	$this->setProductStock($id, $p->quantity, 'set');

	// Set location, if not set use default provided by system
	if ($p->location===false) {
		if ($this->warehouse_location!='')
			$this->setProductWarehouse($id, true, $this->warehouse_location);
	} else if (is_string($p->location) && strlen($p->location)>0 && $p->location!='store') {
		$this->setProductWarehouse($id, true, $p->location);
	}
}
return true;
}

/**
 * addProductImagesFromUpload
 *
 * Attach uploaded images to product of given id
 */
protected Function addProductImagesFromUpload($id, array $images)
{
$errors=array();
$c=count($images['name']);
slog("Images for $id ".$c);
for ($i=0;$i<$c;$i++) {
	if ($images['error'][$i]!=0) {
		$errors[]=$images['name'][$i];
		slog("Image upload error ", $images['error'][$i]);
		continue;
	}

	$ires=$this->addProductImage($id, $images['tmp_name'][$i]);
	slog("Image add for $id ", $ires ? "OK": "Failed ".$images['tmp_name'][$i]." - ".$images['name'][$i]);
}
return true;
}

protected Function addProductImage($id, $image)
{
try {
	$opt = array('resource' => 'images', 'type'=> 'products', 'id'=>$id, 'image'=>$image);
	if (is_numeric($this->shop_id) || $this->shop_id=='all')
		$opt['id_shop']=$this->shop_id;
	return $this->pws->add($opt);
} catch (PrestaShopWebserviceException $ex) {
	$this->err=$ex->getMessage();
	slog("addProductImage failed", $id, $ex);
}
return false;
}

}
?>
