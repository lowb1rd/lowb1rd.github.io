<?php

require('vendor/markdown/markdown_geshi.php');

//--

_::Router(array(), array('ext' => '.html'));

$builder = new \Model\Builder();
_::Registry()->set('builder', $builder);

$log = _::Registry()->get('CLI');

$log->log('CheckCache');
$builder->checkCache('cache/index/');

$log->log('cleanup');
$builder->cleanup();

$log->log('checkFrontpage');
$builder->checkFrontpage();

#$log->log('buildPostMeta');
#$builder->buildPostMeta();

$log->log('DONE');
die();

