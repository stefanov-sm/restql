<?php 
 require(__DIR__.DIRECTORY_SEPARATOR.'include'.DIRECTORY_SEPARATOR.'restql.class.php');
 $svc = new Restql(__DIR__.DIRECTORY_SEPARATOR.'include', __DIR__.DIRECTORY_SEPARATOR.'services');
 $svc -> handle();
