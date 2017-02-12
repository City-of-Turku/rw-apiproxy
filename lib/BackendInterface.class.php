<?php

abstract class BackendInterface
{
// Authentication
abstract public function set_auth($username, $password);
abstract public function get_user();
abstract public function login();
abstract public function logout();

// Locations
abstract public function get_locations();

// Categories
abstract public function get_categories();

// Files (images)
abstract public function upload_file($file, $filename=null, $manage=false);
abstract public function view_file($fid, $data=false, $styles=false);

// Products
abstract public function create_product($type, $sku, $title, $price);
abstract public function index_products();

abstract public function get_product($id);
abstract public function get_product_by_sku($sku);
}
