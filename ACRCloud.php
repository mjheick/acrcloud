<?php

class ACRCloud
{

	private static $config = [
		'sample' => null,
		'sample_bytes' => null,
		'data_type' => 'audio',
		'signature' => null,
		'signature_version' => '1',
		'timestamp' => 0,
		'http_method' => 'POST',
		'http_uri' => '/v1/identify',
		// Below you'll pull from https://us-console.acrcloud.com/service/avr
		'http_domain' => 'acrcloud.com', # replace
		'access_key' => '', # replace
		'access_secret' => '', # replace
	];

	public static function sendFile($filename)
	{
		self::$config['timestamp'] = time();

		$request_url = 'http://' . self::$config['http_domain'] . self::$config['http_uri'];

		$string_to_sign = join("\n", [self::$config['http_method'], self::$config['http_uri'], self::$config['access_key'], self::$config['data_type'], self::$config['signature_version'], self::$config['timestamp']]);
		$signature = hash_hmac('sha1', $string_to_sign, self::$config['access_secret'], true);
		self::$config['signature'] = base64_encode($signature);

		if (class_exists('\CURLFile')) // >php 5.4+
		{
			$cfile = new CURLFile($filename, "mp3", basename($filename));
		}
		else
		{
			$cfile = '@' . $filename;
		}

		$filesize = filesize($filename);
		$post_fields = [
			'sample' => $cfile,
			'sample_bytes' => $filesize,
			'access_key' => self::$config['access_key'],
			'data_type' => self::$config['data_type'],
			'signature' => self::$config['signature'],
			'signature_version' => self::$config['signature_version'],
			'timestamp' => self::$config['timestamp'],
		];
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $request_url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($ch);
		//echo $result;
		//$response = curl_exec($ch);
		//if ($response == true) {
		//    $info = curl_getinfo($ch);
		//} else {
		//    $errmsg = curl_error($ch);
		//    print $errmsg;
		//}
		curl_close($ch);
		return $result;
	}

	public static function parseOutput($json, $idx = 0)
	{
		$retdata = [
			'status' => false,
			'status-code' => -1,
			'hash' => null,
			'artist' => null,
			'album' => null,
			'title' => null,
			'release-date' => '0000-00-00',
		];
		
		$data = json_decode($json, true);
		if (array_key_exists('status', $data) && array_key_exists('code', $data['status']))
		{
			// from observed:
			// -1: None in json. This is function default
			// 0: "Success"
			// 1001: "No Result"
			// 2004: "Can't generate fingerprint"
			// 3003: "limit exceeded, please upgrade your account"
			// 3015: "qps limit exceeded, please upgrade your account"
			$retdata['status-code'] = $data['status']['code'];
			if (($retdata['status-code'] == 0) && isset($data['metadata']['music'][$idx]))
			{
				$retdata['status'] = true;
				$retdata['title'] = trim($data['metadata']['music'][$idx]['title']);
				$retdata['artist'] = trim($data['metadata']['music'][$idx]['artists'][0]['name']);
				$retdata['album'] = trim($data['metadata']['music'][$idx]['album']['name']);
				$retdata['release-date'] = $data['metadata']['music'][$idx]['release_date'];
				$retdata['hash'] = hash('sha1', join('.', $retdata));
			}
		}
		return $retdata;
	}
}
