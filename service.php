<?php

class Traducir extends Service
{
    
    private $languages = null;
    private $accessToken = null;
    private $time = null;
	/**
	 * Function executed when the service is called
	 *
	 * @param Request
	 * @return Response
	 * */
	public function _main(Request $request)
	{
        $this->time = microtime(true);
        try {
            $argument = $request->query;	
            $argument = ' ' . trim($argument) . ' ';
            $argument = str_ireplace(' a ', '', $argument);
            $argument = str_ireplace(' de ', '', $argument);
            $argument = str_ireplace(' del ', '', $argument);
            $argument = str_ireplace(' al ', '', $argument);
            $argument = trim($argument);
            $argument = $this->reparaTildes($argument);
            $language = trim(strtolower($argument));
            $text = trim($this->strip_html_tags($request->body));

            // Cleanning/Decoding the text...
            if(! $this->isUTF8($text)) 
                $text = $this->utf8Encode($text);

            $text = quoted_printable_decode($request->body);
            $text = $this->cleanText($text);
            $text = html_entity_decode($text, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1');

            if($text == '')	{

                if(! $this->isUTF8($request->body)) 
                    $text = $this->utf8Encode($request->body);

                $text = quoted_printable_decode($request->body);

                if(! $this->isUTF8($text)) 
                    $text = $this->utf8Encode($text);
            }

            if($text == ''){
                $response = new Response();
                $response->setResponseSubject("No nos envio el texto a traducir");
                $response->createFromText("Lo sientimos, pero no nos envi&oacute; ning&uacute;n texto a traducir.");
                return $response;
            }

            $textoobig = false;
            $limit = 1000;

            if(strlen($text) > $limit){
                $text = substr($text, 0, $limit);
                $textoobig = true;
            }

            $langs = $this->getLanguages();

            $toLanguage = 'es';

            foreach($langs as $lang => $lname){ 
                $lname = trim(strtolower($lname));
                $lname = htmlentities($lname);
                $lname = str_replace(array('acute;','&','tilde;'),'',$lname);
                if ($lname == $language){
                    $toLanguage = $lang;
                    break;
                }
            }

            $fromLanguage = $this->detect($text);
            $translatedStr = $this->translate($text, $fromLanguage, $toLanguage);	

            $responseContent = array(
                "fromLanguage" => $fromLanguage,
                "toLanguage" => $toLanguage,
                "toLanguageCaption" => $this->languages[$toLanguage],
                "translatedStr" => $translatedStr			
            );

            $response = new Response();
            $response->setResponseSubject("Resultado de traducir '" . substr($text, 0, 20) . "...' al " . $responseContent['toLanguageCaption']);
            $response->createFromTemplate("basic.tpl", $responseContent);

            return $response;
        } catch (Exception $e){
            $response = new Response();
            $response->setResponseSubject('No se pudo traducir su texto');
            $response->createFromText('Estamos presentando problemas para traducir en estos momentos. Intente luego y contacte al soporte t&eacute;cnico.');
            return $response;
        }
	}
	
    /**
     * Load list of languages
     */
	private function getLanguages(){
        if (is_null($this->languages)){
            
            $this->languages = array();
            
            $authHeader = "Authorization: Bearer ". $this->getAccessToken();
            $url = "http://api.microsofttranslator.com/V2/Http.svc/GetLanguagesForTranslate";
            $strResponse = $this->curlRequest($url, $authHeader);
            $xmlObj = simplexml_load_string($strResponse);
            $languageCodes = array();
            foreach($xmlObj->string as $language)
                $languageCodes[] = $language."";    
           
            $locale = 'es';
            $getLanguageNamesurl = "http://api.microsofttranslator.com/V2/Http.svc/GetLanguageNames?locale=$locale";
            $requestXml = $this->createReqXML($languageCodes);
            $curlResponse = $this->curlRequest($getLanguageNamesurl, $authHeader, $requestXml);
            $xmlObj = simplexml_load_string($curlResponse);
            
            $i = 0;
  
            foreach ($xmlObj->string as $language) 
                $this->languages[$languageCodes[$i++]] = $language."";
        }
  
        return $this->languages;
    }
    
    private function getConfig(){
        return array(
            'primary_account_key' => 'SaUc0H0MKD3fbxuZjF305FwnyFaYHO7r5/UMCLfzbZY=',
            'customer_id' => '641f2b6f-fe31-4162-b6cb-7aaa28d2bf5c',
            'email_address' => 'salvi.pascual@outlook.com',
            'account_key' => 'OWgQ9fgfXKcMLO46Jseva3yg2HOZ/aK64JExubw0xuo=',
            'client_id' => '641f2b6f-fe31-4162-b6cb-7aaa28d2bf5c'// 'salvitranslator'
        );
    }
    
	/*
     * Get the access token.
     *
     * @param string $grantType    Grant type.
     * @param string $scopeUrl     Application Scope URL.
     * @param string $clientID     Application client ID.
     * @param string $clientSecret Application client ID.
     * @param string $authUrl      Oauth Url.
     *
     * @return string.
     */
    private function getTokens($grantType, $scopeUrl, $clientID, $clientSecret, $authUrl){

        $ch = curl_init();

        $paramArr = array (
             'grant_type'    => $grantType,
             'scope'         => $scopeUrl,
             'client_id'     => $clientID,
             'client_secret' => $clientSecret
        );

        $paramArr = http_build_query($paramArr);
        curl_setopt($ch, CURLOPT_URL, $authUrl);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $paramArr);
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $strResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);

        if($curlErrno){
            $curlError = curl_error($ch);
            throw new Exception($curlError);
        }

        curl_close($ch);

        $objResponse = json_decode($strResponse);

        if (isset($objResponse->error))
            throw new Exception($objResponse->error_description);

        return $objResponse->access_token;
        
    }
	
	 /*
     * Create and execute the HTTP CURL request.
     *
     * @param string $url        HTTP Url.
     * @param string $authHeader Authorization Header string.
     * @param string $postData   Data to post.
     *
     * @return string.
     *
     */
    private function curlRequest($url, $authHeader, $postData=''){
        $ch = curl_init();
        curl_setopt ($ch, CURLOPT_URL, $url);
        curl_setopt ($ch, CURLOPT_HTTPHEADER, array($authHeader,"Content-Type: text/xml"));
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, False);
        if($postData) {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        $curlResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
		
        if ($curlErrno) {
            $curlError = curl_error($ch);
            throw new Exception($curlError);
        }
        curl_close($ch);
        return $curlResponse;
    }
	
	/**
	 * Translate text
	 *
	 * @param String inputStr
	 * @param String fromLanguage
	 * @param String toLanguage
	 * @param String contentType
	 * @param String category
	 */
	private function translate($inputStr, $fromLanguage = "en", $toLanguage = "es", $contentType ='text/plain', $category = 'general'){
        
        $authHeader = "Authorization: Bearer ". $this->getAccessToken();			
        $params = "appId=&text=".urlencode($inputStr)."&to=".$toLanguage."&from=".$fromLanguage;
        $translateUrl = "http://api.microsofttranslator.com/v2/Http.svc/Translate?$params";
        $curlResponse = $this->curlRequest($translateUrl, $authHeader);
        $xmlObj = simplexml_load_string($curlResponse);
              
        foreach((array)$xmlObj[0] as $val){
            $translatedStr = $val;
        }
        
        if (trim($translatedStr) !== '')
            return $translatedStr;
        
        throw new Exception('Microsoft API Exception');
	}
	
	/**
	 * Detect language
	 *
	 * @param String inputStr
	 * @return String
	 */
	private function detect($inputStr){
        $authHeader   = "Authorization: Bearer ". $this->getAccessToken();
        $detectMethodUrl = "http://api.microsofttranslator.com/V2/Http.svc/Detect?text=".urlencode($inputStr);
        $strResponse = $this->curlRequest($detectMethodUrl, $authHeader);
    
        $xmlObj = simplexml_load_string($strResponse);
                   
        foreach((array)$xmlObj[0] as $val){
            $languageCode = $val;
        }
                 
        return $languageCode;
	}
    
    private function getAccessToken(){
        
        if (microtime(true) - $this->time > 60000)
            $this->accessToken = null;
        
        if (is_null($this->accessToken)){
            $cfg          = $this->getConfig();
            $clientID     = $cfg['client_id'];
            $clientSecret = $cfg['primary_account_key'];
            $authUrl      = "https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/";
            $scopeUrl     = "http://api.microsofttranslator.com";
            $grantType    = "client_credentials";
            $this->accessToken  = $this->getTokens($grantType, $scopeUrl, $clientID, $clientSecret, $authUrl);
        }
        
        return $this->accessToken;
    }
    
    /**
     * Return the lang name by code
     *
     * @param string LanguageCode
     * @return String
     */
    private function getLanguageName($languageCode){
        $locale = 'es'; // return name in spanish
        $getLanguageNamesurl = "http://api.microsofttranslator.com/V2/Http.svc/GetLanguageNames?locale=$locale";
        $requestXml = $this->createReqXML($languageCode);
        $curlResponse = $this->curlRequest($getLanguageNamesurl, $authHeader, $requestXml);
        $xmlObj = simplexml_load_string($curlResponse);
        foreach($xmlObj->string as $language)
           return $language;
    }
	
    /*
     * Create Request XML Format.
     *
     * @param mixed $languageCodes  Language code(s)
     * @return string.
     */
    private function createReqXML($languageCodes) {
      
        if (!is_array($languageCodes))
            $languageCodes = array($languageCodes);
      
        $requestXml = '<ArrayOfstring xmlns="http://schemas.microsoft.com/2003/10/Serialization/Arrays" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">';
      
        foreach($languageCodes as $codes)
            $requestXml .= "<string>$codes</string>";
      
        $requestXml .= '</ArrayOfstring>';
        
        return $requestXml;
    }
    
    
	public function fix_text($text)
	{
		if(! $this->isUTF8($text))
			$text = utf8_encode($text);
		
		$text = html_entity_decode($text, ENT_COMPAT, 'UTF-8');
		$text = htmlentities($text);
		
		return $text;
	}
	
	public function urlencode($text)
	{
		$text = str_replace("\n\r", "\n", $text);
		$text = str_replace("\r\n", "\n", $text);
		$text = str_replace("\n", " ", $text);
		$text = str_replace("\t", " ", $text);
		$text = str_replace("  ", " ", $text);
		$text = str_replace("  ", " ", $text);
		$text = str_replace("  ", " ", $text);
		$text = str_replace(" ", "%20", $text);
		
		return $text;
	}
	
	public function special_chars($text)
	{
		return $text;
		// $l = strlen($text); $ntext = ''; for($i=1025; $i<=1169;$i++){ $text = str_replace(chr($i),'&#'.$i.';',$text); } return htmlspecialchars($text,null,'KOI8-R',false);
	}
	
	public function isUTF8($string)
	{
        if(function_exists("mb_check_encoding") && is_callable("mb_check_encoding"))
        {
            return mb_check_encoding($string, 'UTF8');
        }
        return preg_match('%^(?:
          [\x09\x0A\x0D\x20-\x7E]            # ASCII
        | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
        |  \xE0[\xA0-\xBF][\x80-\xBF]        # excluding overlongs
        | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
        |  \xED[\x80-\x9F][\x80-\xBF]        # excluding surrogates
        |  \xF0[\x90-\xBF][\x80-\xBF]{2}     # planes 1-3
        | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
        |  \xF4[\x80-\x8F][\x80-\xBF]{2}     # plane 16
    	)*$%xs', $string);
    }

	 /**
     * Repair acutes
     *
     * @param string $text
     * @return string
     */
    public function reparaTildes($text)
    {
        $text = htmlentities($text, ENT_COMPAT | ENT_HTML401, 'UTF-8', false);
        $text = str_replace('&', '', $text);
        $text = str_replace('tilde;', '', $text);
        $text = str_replace('acute;', '', $text);
        $text = html_entity_decode($text, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1');
        return $text;
    }
	
	public function utf8Encode($data, $encoding = 'utf-8')
    {
        if(!$this->checkEncoding($data, "utf-8"))
            return utf8_encode($data);
        return $data;
    }
	
	public function checkEncoding($string, $string_encoding)
	{
        $fs = $string_encoding == 'UTF-8' ? 'UTF-32' : $string_encoding;
        $ts = $string_encoding == 'UTF-32' ? 'UTF-8' : $string_encoding;
        return $string === mb_convert_encoding(mb_convert_encoding($string, $fs, $ts), $ts, $fs);
    }
	
	  /**
     * Clean a text
     *
     * @param string $text
     * @return string
     */
    public function cleanText($text, $ps = false, $align = "justify")
    {
        $text = "$text";
		
        if(!$this->isUTF8($text))
            $text = utf8_encode($text);
		
        $text = quoted_printable_decode($text);
        $text = $this->strip_html_tags($text);
        $text = html_entity_decode($text, ENT_COMPAT, 'UTF-8');
        $text = htmlentities($text, ENT_COMPAT, 'UTF-8');
        return $text;
    }

	
	/**
	 * UTF utility
	 *
	 * @param string $utf16
	 * @return string
	 */
	public function utf162utf8($utf16)
	{
		if(function_exists('mb_convert_encoding')) return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16');
		$bytes = (ord($utf16{0}) << 8) | ord($utf16{1});

		if((0x7F & $bytes) == $bytes) return chr(0x7F & $bytes);
		if((0x07FF & $bytes) == $bytes) return chr(0xC0 |(($bytes >> 6) & 0x1F)) . chr(0x80 |($bytes & 0x3F));
		if((0xFFFF & $bytes) == $bytes) return chr(0xE0 |(($bytes >> 12) & 0x0F)) . chr(0x80 |(($bytes >> 6) & 0x3F)) . chr(0x80 |($bytes & 0x3F));
		return '';
	}
	
	/**
 	 * Remove HTML tags, including invisible text such as style and
	 * script code, and embedded objects.
	 * Add line breaks around
	 * block-level tags to prevent word joining after tag removal.
	 */
	public function strip_html_tags($text, $allowable_tags = null)
	{
		// echo "STRIP HTML TAGS\n";
		$text = preg_replace(array(
		// Remove invisible content
		'@<head[^>]*?>.*?</head>@siu',
		'@<style[^>]*?>.*?</style>@siu',
		'@<script[^>]*?.*?</script>@siu',
		'@<object[^>]*?.*?</object>@siu',
		'@<embed[^>]*?.*?</embed>@siu',
		'@<applet[^>]*?.*?</applet>@siu',
		'@<noframes[^>]*?.*?</noframes>@siu',
		'@<noscript[^>]*?.*?</noscript>@siu',
		'@<noembed[^>]*?.*?</noembed>@siu',
		// Add line breaks before and after blocks
		'@</?((address)|(blockquote)|(center)|(del))@iu',
		'@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu',
		'@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu',
		'@</?((table)|(th)|(td)|(caption))@iu',
		'@</?((form)|(button)|(fieldset)|(legend)|(input))@iu',
		'@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu',
		'@</?((frameset)|(frame)|(iframe))@iu'
		), array(
		' ',
		' ',
		' ',
		' ',
		' ',
		' ',
		' ',
		' ',
		' ',
		"\n\$0",
		"\n\$0",
		"\n\$0",
		"\n\$0",
		"\n\$0",
		"\n\$0",
		"\n\$0",
		"\n\$0"
		), $text);

		$text = strip_tags($text, $allowable_tags);
		$text = str_replace('&nbsp;', ' ', $text);
		$text = $this->replaceRecursive(" ", " ", trim(html_entity_decode($text, null, 'UTF-8')));
		$text = str_replace("\r", "", $text);
		$text = $this->replaceRecursive("\n\n", "\n", $text);

		return $text;
	}
	
	public function replaceRecursive($from, $to, $s)
	{
		if($from == $to) return $s;

		$p = 0;
		$max = 100;
		$i = 0;
		do {
			$i++;
			$p = strpos($s, $from, $p);
			if($p !== false)
			$s = str_replace($from, $to, $s);
			if($i >= $max)
			break;
		} while($p !== false);

		return $s;
	}	
}