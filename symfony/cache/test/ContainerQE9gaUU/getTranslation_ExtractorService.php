<?php

use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;

// This file has been auto-generated by the Symfony Dependency Injection Component for internal use.
// Returns the private 'translation.extractor' shared service.

$this->privates['translation.extractor'] = $instance = new \Symfony\Component\Translation\Extractor\ChainExtractor();

$instance->addExtractor('php', new \Symfony\Component\Translation\Extractor\PhpExtractor());

return $instance;
