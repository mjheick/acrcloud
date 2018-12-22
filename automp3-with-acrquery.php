<?php
require_once('ACRCloud.php');
$debug = true;
$rate_limit_sleep = 1;
$basedir = '/home/matt/ACRCloud/auto';
$tmpdir = '/tmp';

/**
 * This script does the following:
 * 1 - Takes in a parameter (assuming mp3)
 * *2 - removed ID3-tags with pecl-id3 (obsoleted cause of automation)
 * 3 - hash the file with sha1 to make something unique
 * 4 - uses ffmpeg and creates 2 smaller files
 * 5 - submits those files to ACRCloud (14-day trial started 21 December)
 * 6,nomatch:
 * - move original(tagged) file to unknown
 * 6,match,not all ACRClodINfo
 * - move original(tagged) file to partial
 * 6,match, and we have all info we need?
 * - re-tag file with new info
 * - create folder structure of (artist first letter)/(artist)/(album)/[filename].mp3
 */

//--- STEP 1: PARAMETER CHECK
// Parameter 1 is a filename
if (!isset($argv[1]))
{
	die('first parameter needs to be a filename' . "\n");
}
if (!is_readable($argv[1]))
{
	die('I cannot use "' . $argv[1] . '" for some reason' . "\n");
}
sleep($rate_limit_sleep); // cause qps
$parameter_filename = $argv[1]; if ($debug) { echo "=> param=${parameter_filename}\n"; }
$basename = basename($parameter_filename); // just the filename

// https://www.ghacks.net/2008/12/21/id3-tag-remover/
// Gonna bulk:pre-remove all ID3 tags anyway
$file_sha1 = hash('sha1', file_get_contents($parameter_filename)); if ($debug) { echo "=> param hash=${file_sha1}\n"; }
// save this in $tmpdir to "move it out of the working tree" so we can work on it. Parallelization ftw
rename($parameter_filename, $tmpdir . '/' . $file_sha1 . '.mp3'); if ($debug) { echo "=> move ${parameter_filename} to " . $tmpdir . '/' . $file_sha1 . '.mp3' . "\n"; }
file_put_contents($tmpdir . '/' . $file_sha1 . '.filename', $parameter_filename); // save the oroginal data, just in case

//--- STEP 4: FFMPEG, make us two files of 10 seconds long
// 10 seconds in for 20 seconds
if ($debug) { echo "=> ffmpeg starting\n"; }
exec('ffmpeg -y -i ' . $tmpdir . '/' . $file_sha1 . '.mp3' . ' -ac 1 -ar 8000 -ss 00:00:10 -t 00:00:20 ' . $tmpdir . '/' . $file_sha1 . '.1.mp3' . '> /dev/null 2>&1');
// 40 seconds in for 20 seconds
if ($debug) { echo "=> ffmpeg starting\n"; }
exec('ffmpeg -y -i ' . $tmpdir . '/' . $file_sha1 . '.mp3' . ' -ac 1 -ar 8000 -ss 00:00:40 -t 00:00:20 ' . $tmpdir . '/' . $file_sha1 . '.2.mp3' . '> /dev/null 2>&1');

//--- STEP 5: Submit!
if ($debug) { echo "=> ACRCloud(1)\n"; }
$json_1 = ACRCloud::sendFile($tmpdir . '/' . $file_sha1 . '.1.mp3');
sleep($rate_limit_sleep);
if ($debug) { echo "=> ${json_1}\n"; }
if ($debug) { echo "=> ACRCloud(2)\n"; }
$json_2 = ACRCloud::sendFile($tmpdir . '/' . $file_sha1 . '.2.mp3');
sleep($rate_limit_sleep);
if ($debug) { echo "=> ${json_2}\n"; }
$data_1 = ACRCloud::parseOutput($json_1); if ($debug) { echo "=> " . var_export($data_1, true) . "\n"; }
$data_2 = ACRCloud::parseOutput($json_2); if ($debug) { echo "=> " . var_export($data_2, true) . "\n"; }

//--- STEP 6, what to do? unknown, partial, keep
// unknown, move file to $basedir . unknown
if ($data_1['status'] == false || $data_2['status'] == false)
{
	// if status-code=1001 then it is a true "unknown", or else we're over quota or rate limited.
	if (($data_1['status-code'] == 1001) && ($data_2['status-code'] == 1001))
	{
		if ($debug) { echo "=> moving ${basename} to unknown\n"; }
		// move file to unknown/sha1_filename
		rename($tmpdir . '/' . $file_sha1 . '.mp3', $basedir . '/unknown/' . $file_sha1 . '_' . $basename);
		// write some debugging info in there
		file_put_contents($basedir . '/unknown/' . $file_sha1 . '_data', $json_1 . "\n" . $json_2 . "\n" . var_export($data_1, true) . "\n" . var_export($data_2, true));
	}
	else // over quota, rate limited, etc. restore original file for attempt again
	{
		if ($debug) { echo "=> moving ${basename} back, qps/quota(" . $data_1['status-code']. "/" . $data_2['status-code'] . ")\n"; }
		rename($tmpdir . '/' . $file_sha1 . '.mp3', $parameter_filename);
	}
}
else
{
	// lets see if we have a "partial scenario", where only the hashs don't work out
	if ($data_1['hash'] != $data_2['hash'])
	{
		if ($debug) { echo "=> moving ${basename} to partial\n"; }
		// move file to partial/sha1_filename
		rename($tmpdir . '/' . $file_sha1 . '.mp3', $basedir . '/partial/' . $file_sha1 . '_' . $basename);
		// write some debugging info in there
		file_put_contents($basedir . '/partial/' . $file_sha1 . '_data', $json_1 . "\n" . $json_2 . "\n" . var_export($data_1, true) . "\n" . var_export($data_2, true));
	}
	else
	{
		if ($debug) { echo "=> moving ${basename} to keep\n"; }
		$folder_root = $basedir . '/keep/' . strtolower(substr($data_1['artist'], 0, 1)) . '/' . $data_1['artist'];
		$new_filename = $data_1['artist'] . '_' . $data_1['album'] . '_' . $data_1['title'] . '_' . strtolower(substr($file_sha1, 0, 7)) . '.mp3';
		foreach (array('/', ':', '~', '`', '$', "\\", '&', '*', '|', '<', '>', '?') as $replacement)
		{
			$new_filename = str_replace($replacement, '_', $new_filename);
		}
		if ($debug) { echo "=> root folder '${folder_root}'\n"; }
		if ($debug) { echo "=> naming this '${new_filename}'\n"; }
		if ($debug) { echo "=> Setting ID3v1 Tag\n"; }
		id3_set_tag($tmpdir . '/' . $file_sha1 . '.mp3', [
			'title' => substr($data_1['title'], 0, 30),
			'artist' => substr($data_1['artist'], 0, 30),
			'album' => substr($data_1['album'], 0, 30),
			'year' => substr($data_1['release-date'], 0, 4),
		]);
		if ($debug) { echo "=> mkdir ${folder_root}\n"; }
		@mkdir($folder_root, 0777, true);
		if ($debug) { echo "=> Moving File\n"; }
		rename($tmpdir . '/' . $file_sha1 . '.mp3', $folder_root . '/' . $new_filename);
	}
}

//rename($tmpdir . '/' . $file_sha1 . '.mp3', $parameter_filename); // move it back
unlink($tmpdir . '/' . $file_sha1 . '.filename');
unlink($tmpdir . '/' . $file_sha1 . '.1.mp3');
unlink($tmpdir . '/' . $file_sha1 . '.2.mp3');
if ($debug) { echo "=> die()\n"; }
die(); 