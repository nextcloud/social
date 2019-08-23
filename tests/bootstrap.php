<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../../../var/www/nextcloud/lib/base.php';

OC_App::loadApp('social');
OC_Hook::clear();
