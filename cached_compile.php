<?php

/**
 * This file is intended to be used in conjunction with Apache2's mod_actions,
 * wherein you can have a .htaccess file like so for automatic compilation:
 *     Action compile-sass /git/phpsass/compile-apache.php
 *     AddHandler compile-sass .sass .scss
 */

$file = $_SERVER['DOCUMENT_ROOT'];
if (isset($_SERVER['PATH_INFO'])) {
	$file .= $_SERVER['PATH_INFO'];
} else if (isset($_SERVER['ORIG_PATH_INFO'])) {
	$file .= $_SERVER['ORIG_PATH_INFO'];
} else {
	$file .= $_SERVER['REQUEST_URI'];
}

$file = realpath($file);
$cachefile = $file.'.cache';

if (!isset($_GET['debug']) && file_exists($cachefile)){
	$needs_compile = 0;
	$f = fopen($cachefile, 'r'); flock($f, LOCK_SH); 
	$cache = unserialize(file_get_contents($cachefile));
	flock($f, LOCK_UN);
	fclose($f);

	if (is_array($cache) && isset($cache['compiled']) && is_array($cache['files'])){
		foreach ($cache['files'] as $cached_file => $time){
			$this_file_time = filemtime($cached_file);
			if ($this_file_time > $cache['compiled'] || $this_file_time > $time){
				$needs_compile = 1;
				break;
			}
		}
		if (!$needs_compile){
			//generate cache header values
			$tsstring = gmdate('D, d M Y H:i:s ', $cache['compiled']) . 'GMT';
			$etag = md5($file . $cache['compiled']);

			//did we get sent a conditional?
			$if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : false;
			$if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : false;

			//check against calculated values
			if ((($if_none_match && rtrim(ltrim($if_none_match, "'\""), "'\"") == $etag) || (!$if_none_match)) && ($if_modified_since && $if_modified_since == $tsstring))
			{
				header('HTTP/1.1 304 Not Modified');
				exit(0);
			}
			header('Content-type: text/css');
			header("Last-Modified: $tsstring");
			header("ETag: \"{$etag}\"");

			echo $cache['rendered'];
			exit(0);
		}
	}
}

require_once './SassParser.php';


$style = 'compressed';
if (strpos($_SERVER['HTTP_HOST'], 'development.')!==false||isset($_GET['nested']))
	$style = 'nested';

$options = array(
	'style' => $style,
	'cache' => FALSE,
	'syntax' => substr($file, -4, 4),
	'debug' => false,
	'callbacks' => array(
		'warn' => 'warn',
		'debug' => 'debug'
	),
	'debug_info' => isset($_GET['debug']),//needed to display sass line numbers for firesass
);

// Execute the compiler.
$parser = new SassParser($options);
try {
	$startTime = time();
	$root_node = $parser->parse($file,true);
	//$root_node->render();
	//$children = $root_node->parseChildren(new SassContext(null));
	//foreach ($children[0]->root as $key=>$value){print "[$key] = ". (is_object($value) ? get_class($value) : $value) . "\n"; }
	//print_r( $children[0]->root->file_list);

	$tsstring = gmdate('D, d M Y H:i:s ', $startTime) . 'GMT';
	$etag = md5($file . $startTime);
	header("Last-Modified: $tsstring");
	header("ETag: \"{$etag}\"");
	header('Content-type: text/css');

	$rendered = $root_node->render();
	echo $rendered;
	//echo "/*"; print_r($root_node->file_list); echo "*/";

	if (!isset($_GET['debug'])) {
		$new_cache = array('files'=> $root_node->file_list, 'rendered'=>$rendered, 'compiled'=>$startTime);
		file_put_contents($cachefile, serialize($new_cache), LOCK_EX);
	}

} catch (Exception $e) {
	header('Content-type: text/css');
	print "/**".$e->getMessage()."**/\n";
	if (isset($cache['rendered']))
		echo $cache['rendered'];
}


function warn($text, $context) {
	print "/** WARN: $text, on line {$context->node->token->line} of {$context->node->token->filename} **/\n";
}
function debug($text, $context) {
	print "/** DEBUG: $text, on line {$context->node->token->line} of {$context->node->token->filename} **/\n";
}