<?php

$config = new SlamCsFixer\Config();
$rules = $config->getRules();
$rules['mb_str_functions'] = false;
$config->setRules($rules);
$config->getFinder()
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/tests')
;

return $config;
