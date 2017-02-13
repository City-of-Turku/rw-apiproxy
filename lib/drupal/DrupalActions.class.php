<?php

require_once('DrupalServiceAPIClient.class.php');

class DrupalActions extends BackendActionsInterface
{
private $d;

public function __construct($url)
{
$this->d=new DrupalServiceAPIClient($url);
$this->d->set_auth_type(AUTH_SESSION);
}

public function set_auth($username, $password)
{
return $this->d->set_auth($username, $password);
}

public function get_user()
{
return $this->d->get_user();
}

public function login()
{
return $this->d->login();
}

public function logout()
{
return $this->d->logout();
}

public function auth_apikey($key)
{
return true;
}

// Locations
public function get_locations()
{
return $this->d->retrieve_view('locations');
}

// Categories
public function get_categories()
{
// return $this->d->retrieve_view('categories');
return false;
}

// Files (images)
public function upload_file($file, $filename=null)
{
return $this->d->upload_file($file, $filename, true);
}

public function view_file($fid, $data=false)
{
return $this->d->view_file($fid, $data, false);
}

// Products
public function create_product($type, $sku, $title, $price)
{
return $this->d->create_product($type, $sku, $title, $price);
}

public function index_products()
{
return $this->d->index_products();
}

public function get_product($id)
{
return $this->d->get_product($id);
}

public function get_product_by_sku($sku)
{
return $this->d->get_product_by_sku($sku);
}


}
