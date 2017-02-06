# RW-API Proxy

API request proxy to a backend service.
License: GPLv3
Not yet fully stable.

The purpose of the API proxy is it remove the need for the client application to know any backend
specific details so that the backend can be changed to some other system or service without
changes to the client software itself. 

This is to be used togeather with the rw-client application available also on github at:
* https://github.com/DigiTurku/rw-client

## Requirements

* PHP 5.4 or greater.

The proxy in itself is self-contained and does not depend on any Drupal or other external code.

## Supported backends

### Drupal 7 service and commerce_service REST API

As the is a proxy for Drupal services the Drupal installation that it talks too need to have
the following modules installed and setup (and their dependencies):
* services
* commerce_services

## Configuration
Copy config.ini.sample to config.ini and modify Drupal service endpoints.

## Responses
All response bodies are in JSON format. 
HTTP error codes are used to report success and failure.

## API endpoints

Implements simple, easy to use API method endpoints for the following functions.
For now, documentation for JSON response details is the code.

## Implemented endpoints (endpoint method description)

### Generic

* /version GET Report API version
* /news GET Get application news feed
* /download/@apk GET Download client application apk package

### Authentication

* /auth/login POST Login to Drupal
* /auth/logout POST Logout from Drupal
* /auth/user GET Get information about logged in user

### Product (Drupal commerce service)

* /product POST add product
* /products GET browse product list
* /products/barcode/@barcode:[A-Z]{3}[0-9]{6,9} GET search product by given barcode string
* /products/image/@fid:[0-9]{1,5} GET get product image by ID, ID is in the product details
* /products/search GET search products

### Locations

* /locations GET Get list of warehouse/locations

### Todo endpoints

These endpoints are planned, but not yet implemented

* /orders
* /products PUT update product
* /products DELETE remove product
* /products/categories

