<?php
/**
 * build.php
 *
 * @created      25.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      WTFPL
 */

namespace codemasher\DrilArchive;

$builddir = __DIR__.'/../.build';
$outdir   = __DIR__.'/../output';

if(!file_exists($builddir)){
	mkdir(directory: $builddir, recursive: true);
}

if(!file_exists($outdir)){
	mkdir(directory: $outdir, recursive: true);
}

require_once __DIR__.'/get-timeline.php';
require_once __DIR__.'/parse-dril-csv.php';
require_once __DIR__.'/compile-dril-timeline.php';
