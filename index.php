<?PHP
function curl_exec_follow(/*resource*/$ch, /*int*/&$maxredirect = null) {
	$mr = $maxredirect === null ? 5 : intval($maxredirect);
	if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) {
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $mr > 0);
		curl_setopt($ch, CURLOPT_MAXREDIRS, $mr);
	} else {
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		if ($mr > 0) {
			$newurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

			$rch = curl_copy_handle($ch);
			curl_setopt($rch, CURLOPT_HEADER, true);
			curl_setopt($rch, CURLOPT_HTTPHEADER, array('WT-issued: true'));
			curl_setopt($rch, CURLOPT_NOBODY, true);
			curl_setopt($rch, CURLOPT_FORBID_REUSE, false);
			curl_setopt($rch, CURLOPT_RETURNTRANSFER, true);
			do {
				curl_setopt($rch, CURLOPT_URL, $newurl);
				$header = curl_exec($rch);
				if (curl_errno($rch)) {
					$code = 0;
				} else {
					$code = curl_getinfo($rch, CURLINFO_HTTP_CODE);
					if ($code == 301 || $code == 302) {
						preg_match('/Location:(.*?)\n/', $header, $matches);
						$newurl = trim(array_pop($matches));
					} else {
						$code = 0;
					}
				}
			} while ($code && --$mr);
			curl_close($rch);
			if (!$mr) {
				if ($maxredirect === null) {
					trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING);
				} else {
					$maxredirect = 0;
				}
				return false;
			}
			curl_setopt($ch, CURLOPT_URL, $newurl);
		}
	}
	return curl_exec($ch);
}

function proxy($url, $rate, $wait, $gzip, $sendCookies, $userAgent, $getAllHeaders) {

	global $HEADER_WHITE_LIST, $HEADER_BLACK_LIST, $BUFFER_CHUNK_SIZE, $DEFAULT_RATE;
	
	if($gzip != "true" && $gzip != "false" && $gzip != "default") {
		print 'ERROR: gzip not recognized: ' . $gzip . ' (true|false|default)';
		die();	
	}
	
	if (!$rate) {
		$rate = $DEFAULT_RATE;
	}
	
	if (!$url) {
		print 'ERROR: url not specified';
		die();
	} else {


		//Check if server responds gzip
		$chhead = curl_init($url);
		curl_setopt($chhead, CURLOPT_HTTPHEADER, array('WT-issued: true'));
		curl_setopt($chhead, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($chhead, CURLOPT_NOBODY, true);
		curl_setopt($chhead, CURLOPT_ENCODING, "gzip");
		curl_setopt($chhead, CURLOPT_HEADER, true);
		curl_setopt($chhead, CURLOPT_USERAGENT, $userAgent);
		
		$doGzip = true;		
		if($gzip == "default") {
			if(!preg_match('/Content\-Encoding[ ]*\:[ ]*gzip/i', curl_exec_follow($chhead))){
				$doGzip = false;
			} else {
				$doGzip = true;
			}
		} else if ($gzip == "false") {
			$doGzip = false;
		} else {
			$doGzip = true;
		}
		curl_close($chhead);

		
		$ch = curl_init($url);
	
		if (strtolower($_SERVER['REQUEST_METHOD']) == 'post') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $_POST);
		}
	
		if ($sendCookies) {
			$cookie = array();
			foreach ($_COOKIE as $key => $value) {
				$cookie[] = $key . '=' . $value;
			}
			$cookie = implode('; ', $cookie);
	
			curl_setopt($ch, CURLOPT_COOKIE, $cookie);
		}
	
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('WT-issued: true'));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
				
		if($doGzip) {
			curl_setopt($ch, CURLOPT_ENCODING, "gzip");
		}


		$response = curl_exec_follow($ch);
		
		if(!$response) {
			print 'ERROR: could not process: ' . $url;
			die();			
		}
	
	
		list($header, $contents) = preg_split('/([\r\n][\r\n])\\1/', $response, 2);
			
		curl_close($ch);
		
		
		
		$header_text = preg_split('/[\r\n]+/', $header);
		

		foreach ($header_text as $header) {
			if (
				(preg_match('/^(?:' . implode('|', $HEADER_WHITE_LIST) . '):/i', $header) || $getAllHeaders) &&
				!(preg_match('/^(?:' . implode('|', $HEADER_BLACK_LIST) . '):/i', $header))
			) {
				header($header);
			}
		}
		
		$aContent = str_split($contents, $BUFFER_CHUNK_SIZE);
		$chunks = count($aContent);
		$compressionRate = strlen($contents) / strlen(gzencode($contents));
		
		if($doGzip) {
			$realRate = $rate * $compressionRate;
		} else {
			$realRate = $rate;
		}
	
		header("WT-handled: true");

		header("WT-chunks: " . $chunks);
		header("WT-chunks-size: " . $BUFFER_CHUNK_SIZE);

		if($doGzip) {
			header("WT-compression-rate: " . $compressionRate);
		}
		
		header("WT-param-rate: " . $rate . "kb/s");
		header("WT-param-wait: " . $wait);
		header("WT-param-gzip: " . $gzip);
		header("WT-param-user-agent: " . $userAgent);
		header("WT-param-get-all-headers: " . ($getAllHeaders ? "true":"false"));
		header("WT-param-send-cookies: " . ($sendCookies ? "true":"false"));
		
		
		if($doGzip) {
			ob_start("ob_gzhandler");
		} else {
			ob_start();
		}
		
		
		usleep($wait * 1000);
		for($i=0; $i < $chunks; $i++) {
			echo $aContent[$i];
			ob_flush();
			usleep((strlen($aContent[$i])/($realRate*1024))*1000000);
		}
		
		echo(ob_get_clean());		
		ob_end_flush();
	}
}

function restHandler() {
	$requestUrl = $_SERVER["REQUEST_URI"];
	$handlerUrl = $_SERVER["PHP_SELF"];
	$aRequestUrl = split("/",$requestUrl);
	$aHandlerUrl = split("/", $handlerUrl);
	
	array_splice($aRequestUrl,0,count($aHandlerUrl) - 1);

	$aUnamedParams = array();
	$aUrl =array();
	$inUrl = false;
	for($i = 0; $i < count($aRequestUrl); $i++) {
		if(strtolower($aRequestUrl[$i]) == "http:") $inUrl = true;
		if(!$inUrl) {
			array_push($aUnamedParams, $aRequestUrl[$i]);
		} else {
			array_push($aUrl, $aRequestUrl[$i]);
		}
	}
	
	if(!$inUrl) {
		echo "ERROR: no url";
		die();		
	}
	
	$aParamNames = array(
		"rate",
		"wait",
		"gzip",
		"userAgent",
		"getAllHeaders",
		"sendCookies"
	);
	
	if(count($aUnamedParams) > count($aParamNames)) {
		echo "ERROR: Too many params";
		die();
	}
	
	array_splice($aParamNames,count($aUnamedParams));
	
	if(count($aUnamedParams) > 0) {
		$aParams = array_combine($aParamNames, $aUnamedParams);
	}
	$aParams["url"] = implode("/", $aUrl);
	
	$aParams['url'] = !empty($aParams['url']) ? $aParams['url'] : false;
	$aParams['sendCookies'] = !empty($aParams['sendCookies']) ? strtolower($aParams['sendCookies']) == "true" : true;
	$aParams['userAgent'] = !empty($aParams['userAgent']) ? $aParams['userAgent'] : $_SERVER['HTTP_USER_AGENT'];
	$aParams['getAllHeaders'] = !empty($aParams['getAllHeaders']) ? strtolower($aParams['getAllHeaders']) == "true" : true;
	$aParams['wait'] = !empty($aParams['wait']) ? intval($aParams['wait']) : 0;
	$aParams['rate'] = !empty($aParams['rate']) ? intval($aParams['rate']) : $DEFAULT_RATE;
	$aParams['gzip'] = !empty($aParams['gzip']) ? strtolower($aParams['gzip']) : "default"; 

	return 	$aParams;
}

$HEADER_WHITE_LIST = array("Content-Type", "Content-Language", "Set-Cookie");
$HEADER_BLACK_LIST = array("Transfer-Encoding", "Content-Encoding","Content-Length");
$BUFFER_CHUNK_SIZE = 4096;
$DEFAULT_RATE = 100000;

$aParams = restHandler();

proxy($aParams['url'], $aParams['rate'], $aParams['wait'], $aParams['gzip'], $aParams['sendCookies'], $aParams['userAgent'], $aParams['getAllHeaders']);

?>