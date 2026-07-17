<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->setAutoRoute(false);

// Root-level S3 operations, e.g. ListBuckets.
$routes->get('/', 'S3Controller::root');
$routes->options('/', 'S3Controller::optionsRoot');

// Bucket+object operations. This must be above the bucket-only route.
$routes->match(['GET','PUT','POST','DELETE','HEAD','OPTIONS'], '(:segment)/(.*)', 'S3Controller::object/$1/$2');

// Bucket-only operations.
$routes->match(['GET','PUT','POST','DELETE','HEAD','OPTIONS'], '(:segment)', 'S3Controller::bucket/$1');
