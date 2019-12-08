<?php

$config = new SlamCsFixer\Config(SlamCsFixer\Config::LIB, array(
    'no_superfluous_phpdoc_tags' => false,
));
$config->getFinder()
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/tests')
;

return $config;
