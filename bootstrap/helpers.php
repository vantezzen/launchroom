<?php

$files = glob(__DIR__.'/../app/Support/*.php');
foreach ($files as $file) {
    require $file;
}
