<?php
/*
	Am_of_backup 2021.05.08

	Usage:
    backup.php [ALL|ALT|%ALTPATH|%DATE|/DATE] [%EXCLUDE_DIR]

    Perform a (recursive) backup of current directory.
	For incremental backups use /DATE to use current date for folder name, or set custom name (other than "ALL" or "ALT" and not containing character ":").
	ALL (default) performs backup to the location specified in SETTINGS below.
	ALT performs backup to alternate location specified in SETTINGS.
	Backup location can also be specified in the command line - it must be full path including a drive letter (ie. E:/BACKUP), 
	otherwise incremental backup will be performed and the argument will be used as a folder name.

	Set backup locations below in SETTINGS (BACKUP_DIR).
	Other settings:
	SKIP_CHECKSUM = true	=> Copies all files, without checking for updates.
	SKIP_CHECKSUM = false	=> Copies only new and updated files (CRC32 hash is used to detect changes).
	exclude_dirs			=> Directories to exclude from backup (one additional exclude dir can also be set as a 2nd argument - %EXCLUDE_DIR)
	BACKUP_EXT				=> File types to backup
	
*/
date_default_timezone_set('Europe/Zagreb');
$arg1 = (isset($argv[1])?strtolower($argv[1]):0);

/****************** SETTINGS: START ******************/
$exclude_dirs = array('MathJax-master', 'SOURCES', 'SOURCES_CATEGORIZED', '!RELEASE_CLEAN', '!RELEASE_MIRROR');	// Directories to exclude from backup

// Backup only specified file types (extensions) - use | as delimiter, or set to empty ('') to backup all files, regardless of type
define('BACKUP_EXT', 'htaccess|bat|png|jpg|jpeg|ttf|otf|svg|gif|webp|zip|exe|mov|mp4|bin|m|pdf|psd|php|db|code|htm|html|txt|srt|xml|yml|xls|xlsx|js|css|sh|me|md|doc|ini|rdf|conf|cfg|java|json');

if (isset($argv[1]) && $arg1 != 'all' && $arg1 != 'alt') {
	if (strpos($arg1, ':') !== false) {
		define('BACKUP_DIR', $argv[1]);							// Backup location specified in a script argument
		define('SKIP_CHECKSUM', false);
	}
	else {
		if ($arg1 == '/date') $argv[1] = date('Y.m.d');	
		define('BACKUP_DIR', 'E:/BCKUP/FINAL_DATED/'.$argv[1]);		// Location for incremental backups
		@mkdir(BACKUP_DIR);
		define('SKIP_CHECKSUM', true);
	}
}
elseif (isset($argv[1]) && $arg1 == 'alt') {
	define('BACKUP_DIR', 'G:/MY/FINAL');						// Alternate backup location
	define('SKIP_CHECKSUM', false);
}
else {
	define('BACKUP_DIR', 'E:/BCKUP/FINAL_BACKUP');				// Backup location
	define('SKIP_CHECKSUM', false);
}
/******************* SETTINGS: END *******************/

if (isset($argv[2])) $exclude_dirs[] = $argv[2];

if (strcasecmp(substr(PHP_OS, 0, 3), 'WIN') == 0)
	define('WIN_OS', true);
else
	define('WIN_OS', false);

if (WIN_OS)
	@$mode_con = `mode con`;

if (WIN_OS && preg_match('/Columns:\s+([0-9]+)/', $mode_con, $matches))
	define('CHAR_LIMIT', $matches[1]);
else
	define('CHAR_LIMIT', 128);

$cur_dir = getcwd();

// returns all files in a dir and subdirs,
// only -> returns only files with specified extensions (delimiter = |)
function traverse_hierarchy($path, $only='') {
	global $exclude_dirs, $cur_dir;
    $return_array = array();
    $dir = opendir($path);
    while(($file = readdir($dir)) !== false) {
        if($file == '.' || $file == '..') continue;
        $fullpath = $path . '/' . $file;
        if(is_dir($fullpath)) {
			if (in_array($file, $exclude_dirs)) continue;
			$backup_dir = str_replace($cur_dir, BACKUP_DIR, $fullpath);
			if (!file_exists($backup_dir))
				mkdir($backup_dir);
            $return_array = array_merge($return_array, traverse_hierarchy($fullpath, $only));
		}
        else {
            if ($only != '')
                if (!preg_match('/\.('.$only.')$/i', $file)) continue;
            $return_array[] = $fullpath;
        }
    }
    return $return_array;
}

function padStr($str, $cnt=3) {
	while (strlen($str) < $cnt)
		$str = ' '.$str;
	return $str;
}
function niceStr($str, $cnt=CHAR_LIMIT-8) {
	while (strlen($str) < $cnt)
		$str .= ' ';
	return $str;
}
function limitStr($str, $limit=CHAR_LIMIT-8) {
	if (strlen($str) > $limit)
		$str = substr($str, 0, $limit-3).'...';
	return $str;
}

$time_start = time();
echo date('Y.m.d. H:i:s').": Processing ...\n";
$files = traverse_hierarchy(getcwd(), BACKUP_EXT);
$i = 0;
$hashes = '';
$files_cnt = count($files);
$checksum_arr = array();
if (!SKIP_CHECKSUM && file_exists(BACKUP_DIR.'/.checksums')) {
	$checksums = explode(PHP_EOL, file_get_contents(BACKUP_DIR.'/.checksums'));
	foreach ($checksums as $line) {
		$tmp = explode('" ', $line);
		if (!isset($tmp[1])) continue;
		$fname = str_replace('"', '', $tmp[0]);
		$checksum_arr[md5($tmp[1].$fname)] = $fname;
	}
}
echo date('Y.m.d. H:i:s').": Found $files_cnt file(s)\n";
echo date('Y.m.d. H:i:s').": Copying to ".BACKUP_DIR."...\n";
$skipped = 0; $new = 0; $updated = 0;
foreach ($files as $f) {
	//$fg = file_get_contents($f);
	$f_in = $f;
	$f = str_replace($cur_dir.'/', '', $f);
	if (!SKIP_CHECKSUM) {
		$crc32 = hash_file('CRC32', $f_in);
		$hashes .= '"'.$f.'" '.$crc32.PHP_EOL;
	}
	$b_file_exists = file_exists(BACKUP_DIR.'/'.$f);
	if (!SKIP_CHECKSUM && $b_file_exists && isset($checksum_arr[md5($crc32.$f)])) {
		$work = 'SKIPPING';
		++$skipped;
	}
	else {
		//echo "\r".$crc32.': '.$f."                          \n";
		$work = 'UPDATED';
		if ($b_file_exists) ++$updated;
		else { ++$new; $work = 'NEW'; }
		$mtime = filemtime($f_in);
		copy($f_in, BACKUP_DIR.'/'.$f);
		touch(BACKUP_DIR.'/'.$f, $mtime);	// We want to preserve file modification timestamp
		echo "\r ".niceStr(limitStr($f, CHAR_LIMIT-(5+strlen($work))).' ['.$work.']', CHAR_LIMIT-2).PHP_EOL;
	}
	echo "\r".'['.padStr(round(100 * (++$i/$files_cnt))).'%] '.niceStr(limitStr($f, CHAR_LIMIT-(11+strlen($work))).' ['.$work.']');
}
echo "\r       ".niceStr('');
if (!SKIP_CHECKSUM) file_put_contents(BACKUP_DIR.'/.checksums', $hashes);
$time_total = time() - $time_start;
echo "\n".date('Y.m.d. H:i:s').": Done in $time_total s!".($skipped==$files_cnt?' (NO UPDATES)':" (NEW FILES: $new, UPDATED: $updated)").PHP_EOL;

?>