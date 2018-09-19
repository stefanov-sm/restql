<?php 
 require('include/restql.class.php');
 $svc = new Restql(__DIR__.DIRECTORY_SEPARATOR.'include', __DIR__.DIRECTORY_SEPARATOR.'services');
 $svc -> handle();