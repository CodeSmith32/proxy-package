<?php
/**
  ProxyManager - matches what http://hosts.cx does

  Version 2.2.0

  Helpful resource: http://github.com/cowboy/php-simple-proxy/raw/master/ba-simple-proxy.php
**/

class ProxyManager {
  private $oldhost = null;
  private $preg_oldhost = null;
  private $newhost = null;
  private $preg_newhost = null;
  private $ip = null;
  private $port = null;
  private $logTime = '<unknown>';

  private $debug = false; // logs full request-response data sent to/from the true server

  private $preserved_request_headers = array(
    'a-im',
    'accept',
    'accept-charset',
    'accept-encoding',
    'accept-language',
    'accept-datetime',
    'access-control-request-method',
    'access-control-request-headers',
    'authorization',
    'cache-control',
    'connection',
    'content-length',
    'content-md5',
    'content-type',
    // 'cookie',
    'date',
    'expect',
    'forwarded',
    'from',
    // 'host',
    'http2-settings',
    'if-match',
    'if-modified-since',
    'if-none-match',
    'if-range',
    'if-unmodified-since',
    'max-forwards',
    // 'origin',
    // 'pragma',
    'proxy-authorization',
    'range',
    // 'referer',
    'te',
    'user-agent',
    'upgrade',
    'via',
    'warning',
  );
  private $preserved_response_headers = array(
    // 'access-control-allow-origin',
    'access-control-allow-credentials',
    'access-control-expose-headers',
    'access-control-max-age',
    'access-control-allow-methods',
    'access-control-allow-headers',
    'accept-patch',
    'accept-ranges',
    'age',
    'allow',
    'alt-svc',
    'cache-control',
    'connection',
    'content-disposition',
    'content-encoding',
    'content-language',
    'content-length',
    'content-location',
    'content-md5',
    'content-range',
    'content-type',
    'date',
    'delta-base',
    'etag',
    'expires',
    'im',
    'last-modified',
    'link',
    // 'location',
    'p3p',
    'pragma',
    'proxy-authenticate',
    'public-key-pins',
    'retry-after',
    'server',
    // 'set-cookie',
    'strict-transport-security',
    'trailer',
    'transfer-encoding',
    'tk',
    'upgrade',
    'vary',
    'via',
    'warning',
    'www-authenticate',
    'x-frame-options',
  );

  private $handled_mimes = array(
    'text/plain',
    'text/html',
    'application/html',
    'text/xhtml',
    'application/xhtml',
    'application/xhtml+xml',
    'text/xml',
    'application/xml',
    'application/vnd.mozilla.xul+xml',
    'text/csv',
    'text/svg+xml',
    'image/svg+xml',
    'text/css',
    'text/javascript',
    'text/json',
    'application/json',
    'application/ld+json',
  );
  private $no_send_headers = array(
    'content-length',
    'content-encoding',
    'transfer-encoding',
  );

  public $statusline;
  public $originalHeaders;
  public $originalContents;
  public $headers;
  public $contents;

  public $mimetype;

  function __construct($params) {
    $oldhost = $params['oldhost'];
    $newhost = $params['newhost'];
    $ip      = $params['ip'];
    $port    = isset($params['port']) ? $params['port'] : 80;
    $debug   = !empty($params['debug']);
    if(isset($params['handled_mimes']) && (is_array($params['handled_mimes']) || $params['handled_mimes'] === 'all'))
      $this->handled_mimes = $params['handled_mimes'];

    if(preg_match('/[^\w\.-]/',$oldhost) === 1)
      throw new Exception('Old host has invalid characters');
    if(preg_match('/[^\w\.-]/',$newhost) === 1)
      throw new Exception('New host has invalid characters');

    $this->oldhost = $oldhost;
    $this->preg_oldhost = '%'.preg_quote($oldhost,'%').'%i';
    $this->newhost = $newhost;
    $this->preg_newhost = '%'.preg_quote($newhost,'%').'%i';
    $this->ip = $ip;
    $this->port = $port;
    $this->debug = $debug;
  }

  private function prepareRequest($ch) {
    $reqHeaders = getallheaders();

    curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
    if(strtolower($_SERVER['REQUEST_METHOD']) == 'post') {
      $post = $this->buildPostData($reqHeaders);

      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);

    // replace domain in headers
    $headers = array();
    foreach($reqHeaders as $header => $value) {
      $header = strtolower($header);

      if(in_array($header, $this->no_send_headers))
        continue;

      if(!in_array($header, $this->preserved_request_headers))
        $value = preg_replace($this->preg_newhost,$this->oldhost, $value);

      $headers []= "$header: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // specify custom DNS
    curl_setopt($ch, CURLOPT_RESOLVE, array("{$this->oldhost}:{$this->port}:{$this->ip}"));

    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if($this->debug) {
      $statusline = (strtolower($_SERVER['REQUEST_METHOD']) == 'post' ? 'POST ' : 'GET ') . $_SERVER['REQUEST_URI'] . ' HTTP/1.1';
      file_put_contents("{$this->logTime}.request", $statusline . "\n" . implode("\n",$headers) . "\n\n" . $post);
    }
  }

  private function prepareResponse($ch) {
    $data = curl_exec($ch);

    if(empty($data))
      throw new Exception(curl_error($ch));

    if($this->debug) file_put_contents("{$this->logTime}.response", $data);

    curl_close($ch);

    // get rid of 'continue' headers
    $data = preg_replace('%^(http/[\d\.]+\\s*100\\s*continue\\r?\\n\\r?\\n)*%i','', $data);
    list($header,$contents) = preg_split('/\\r?\\n\\r?\\n/',$data,2);

    $this->originalContents = $contents;

    // split header text into an array
    $header_text = preg_split('/[\r\n]+/', $header);
    $originalHeaders = array();
    $headers = array();
    $statusheader = null;
    $mime = '';

    // parse headers / replace domain in response headers
    foreach($header_text as $header)
      if(preg_match('/^(.+?):\s+(.*)$/i', $header, $matches) === 1) {
        $hdr = $matches[1];
        $val = $matches[2];

        $originalHeaders []= array('name'=>$hdr, 'value'=>$val);
        $hdr = strtolower($hdr);

        if(!in_array($hdr, $this->no_send_headers)) {
          if(!in_array($hdr, $this->preserved_response_headers))
            $val = preg_replace($this->preg_oldhost,$this->newhost, $val);

          $headers []= array('name'=>$hdr, 'value'=>$val);
        }

        if($hdr == 'content-type' && preg_match('%^(.*?)(?:;|\\s|$)%',$val,$m)===1)
          $mime = $m[1];

      } else if(preg_match('/^HTTP/',$header))
        $statusheader = $header;

    // replace in html / css / js / etc. response content
    if($this->handled_mimes === 'all' || in_array($mime, $this->handled_mimes))
      $contents = preg_replace($this->preg_oldhost,$this->newhost,$contents);

    $this->statusline = $statusheader;
    $this->headers = $headers;
    $this->contents = $contents;

    $this->mimetype = $mime;
  }

  private function processPostVar($key,$value,$cb,$first=true) {
    if($first) $key = preg_replace($this->preg_newhost,$this->oldhost,$key);
    $data = '';

    if(is_array($value)) {
      foreach($value as $k => $v) {
        $k = preg_replace($this->preg_newhost,$this->oldhost,$k);
        $newkey = "{$key}[$k]";
        $data .= $this->processPostVar($newkey,$v,$cb,false);
      }
    } else {
      $value = preg_replace($this->preg_newhost,$this->oldhost,$value);
      $data = call_user_func($cb,$key,$value);
    }

    return $data;
  }

  private $boundary;
  private function multipartField($key,$value) {
    $key = preg_replace('/[\\r\\n"]/', '', $key); // not sure if these chars can be escaped
    return "--{$this->boundary}\r\n"
            . "content-disposition: form-data; name=\"$key\"\r\n"
            . "\r\n"
            . "$value\r\n";
  }

  private function buildPostData($headers) {
    $contentType = null;
    foreach($headers as $header => $value)
      if(strtolower($header) == 'content-type') {
        $contentType = array_map('trim',explode(';',$value));
        break;
    }

    // handle multipart/form-data
    if(!empty($contentType) && strtolower($contentType[0]) == 'multipart/form-data') {
      $boundary = '';
      foreach($contentType as $prop) {
        $prop = explode('=',$prop,2);
        if(count($prop) > 1 && strtolower($prop[0]) == 'boundary')
          $boundary = $prop[1];
      }

      $this->boundary = $boundary;
      $post = '';
      foreach($_POST as $key => $value) {
        $post .= $this->processPostVar($key,$value,array($this,'multipartField'));
      }

      foreach($_FILES as $key => $file) {
        $name = $file['name'];
        $key = preg_replace($this->preg_newhost,$this->oldhost,$key);
        $key = preg_replace('/[\\r\\n"]/', '', $key); // not sure if these chars can be escaped
        $name = preg_replace('/[\\r\\n"]/', '', $name); // not sure if these chars can be escaped
        $data = file_get_contents($file['tmp_name']);
        unlink($file['tmp_name']);

        $post .= "--$boundary\r\n"
            . "content-disposition: form-data; name=\"$key\"; filename=\"$name\"\r\n"
            . "\r\n"
            . "$data\r\n";
      }

      $post .= "--$boundary--";

      return $post;
    }

    // handle application/x-www-form-urlencoded
    return preg_replace( // replace domain in post data
      '~'.preg_quote(urlencode($this->newhost),'~').'~i',
      urlencode($this->oldhost),
      http_build_query($_POST)
    );
  }

  private function randhex($l) {
    $str = ''; $al = '0123456789abcdef';
    while($l--) $str .= $al[rand(0,15)];
    return $str;
  }

  function proxy() {
    if($this->debug) $this->logTime = date('Ymd His ').$this->randhex(6);

    try {

      $url = $_SERVER['REQUEST_SCHEME'] . '://' . $this->oldhost . $_SERVER['REQUEST_URI'];
      $ch = curl_init($url);
      $this->prepareRequest($ch);
      $this->prepareResponse($ch);

    } catch(Exception $e) {

      $this->statusline = 'HTTP/1.1 500 Internal Server Error';
      $this->contents = '
        <!DOCTYPE html>
        <html>
        <head>
          <title></title>
        </head>
        <body>
          <p>Failed to load page via proxy.</p>
          <p>'.$e.'</p>
        </body>
        </html>';
      $this->headers = array();

      if($this->debug) file_put_contents("{$this->logTime}.error", $e);

    }
  }

  function push() {
    header($this->statusline);

    foreach($this->headers as $header)
      header($header['name'].': '.$header['value'], false);

    echo $this->contents;
  }
}

?>