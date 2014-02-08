<?php if(version_compare(PHP_VERSION,'5.2.0')<0){throw new Exception('This library requires PHP 5.2.0 or later.');}abstract class TwistBase{final protected static function filter($input,$array_demention=0){$array_demention=(int)$array_demention;if($array_demention<1){switch(true){case is_array($input):case is_object($input)and!method_exists($input,'__toString'):return '';}return (string)$input;}$output=array();foreach((array)$input as $key=>$value){$output[self::filter($key)]=self::filter($value,$array_demention-1);}return $output;}}class TwistCredential extends TwistBase{private $userAgent='TwistOAuth';private $consumerKey='';private $consumerSecret='';private $requestToken='';private $requestTokenSecret='';private $accessToken='';private $accessTokenSecret='';private $userId='';private $screenName='';private $password='';private $authenticityToken='';private $verifier='';private $history=array();private $cookies=array();final public function __construct($consumerKey='',$consumerSecret='',$accessToken='',$accessTokenSecret='',$screenName='',$password=''){$this->setConsumer($consumerKey,$consumerSecret)->setAccessToken($accessToken,$accessTokenSecret)->setScreenName($screenName)->setPassword($password);}final public function __toString(){$string='';if($this->screenName!==''){$string.="@{$this->screenName}";}if($this->userId!==''){$string.="(#{$this->userId})";}return $string;}final public function __get($name){if(!property_exists($this,$name=self::filter($name))){throw new OutOfRangeException("Invalid property name: {$name}");}return $this->$name;}final public function setUserAgent($userAgent){$this->userAgent=self::filter($userAgent);return $this;}final public function setConsumer($consumerKey='',$consumerSecret=''){$this->consumerKey=self::filter($consumerKey);$this->consumerSecret=self::filter($consumerSecret);return $this;}final public function setRequestToken($requestToken='',$requestTokenSecret=''){$this->requestToken=self::filter($requestToken);$this->requestTokenSecret=self::filter($requestTokenSecret);return $this;}final public function setAccessToken($accessToken='',$accessTokenSecret=''){$this->accessToken=self::filter($accessToken);$this->accessTokenSecret=self::filter($accessTokenSecret);return $this;}final public function setUserId($userId=''){$this->userId=self::filter($userId);return $this;}final public function setScreenName($screenName=''){$this->screenName=self::filter($screenName);return $this;}final public function setPassword($password=''){$this->password=self::filter($password);return $this;}final public function setAuthenticityToken($authenticityToken=''){$this->authenticityToken=self::filter($authenticityToken);return $this;}final public function setVerifier($verifier=''){$this->verifier=self::filter($verifier);return $this;}final public function getAuthorizeUrl($force_login=false){return $this->getAuthUrl('authorize',$force_login);}final public function getAuthenticateUrl($force_login=false){return $this->getAuthUrl('authenticate',$force_login);}final public function setHistory($name,$value){$this->history[self::filter($name)]=(int)$value;return $this;}final public function setCookie($key,$value){$this->cookies[self::filter($key)]=self::filter($value);return $this;}private function getAuthUrl($mode,$force_login){$url="https://api.twitter.com/oauth/{$mode}";$params=array('oauth_token'=>$this->requestToken,'force_login'=>$force_login?'1':null );return $url.'?'.http_build_query($params,'','&');}}final class TwistException extends RuntimeException{private $request=null;public function __construct($message,$code,TwistRequest $request=null){$this->request=$request;parent::__construct($message,$code);}public function __toString(){return sprintf('[%d] %s',$this->getCode(),$this->getMessage());}public function getRequest(){return $this->request;}}class TwistExecuter extends TwistUnserializable{const STEP_WRITE_REQUEST_HEADERS=0;const STEP_READ_RESPONSE_HEADERS=1;const STEP_READ_RESPONSE_LONGED=2;const STEP_READ_RESPONSE_CHUNKED_SIZE=3;const STEP_READ_RESPONSE_CHUNKED_CONTENT=4;const STEP_FINISHED=5;private $callback;private $args=array();private $interval=0;private $timer=0;private $jobs=array();private $timeout=1.0;final public function __construct($args){if(!$args=func_get_args()){throw new InvalidArgumentException('Required at least 1 TwistReuest instance.');}$this->jobs=array();array_walk_recursive($args,array($this,'setRequest'));}final public function setInterval($callback=null,$interval=0,array $args=array()){$this->callback=is_callable($callback)?$callback:null;$this->interval=abs((float)$interval);$this->args=$args;$this->timer=microtime(true)+$this->interval;return $this;}final public function setTimeout($sec=1.0){$this->timeout=abs((float)$sec);return $this;}final public function start(){foreach($this->jobs as $job){self::initialize($job);switch(true){case!($job->request instanceof TwistRequest):case!($job->request->credential instanceof TwistCredential):continue 2;}self::connect($job);}return $this;}final public function abort(){foreach($this->jobs as $job){self::initialize($job);}return $this;}final public function isRunning(){foreach($this->jobs as $job){if($job->step!==self::STEP_FINISHED){return true;}}return false;}final public function run(){if($this->callback){$time=microtime(true);if($this->timer<=$time){$this->timer+=$this->interval;call_user_func_array($this->callback,$this->args);}}$read=$write=$results=array();$except=null;foreach($this->jobs as $i=>$job){switch($job->step){case self::STEP_FINISHED:continue 2;case self::STEP_WRITE_REQUEST_HEADERS:$write[$i]=$job->fp;continue 2;default:$read[$i]=$job->fp;}}if(!$read and!$write){return $results;}$sec=(int)$this->timeout;$msec=(int)($this->timeout*1000000)%1000000;if(false===stream_select($read,$write,$except,$sec,$msec)){throw new TwistException('Failed to select stream.',0);}foreach($this->jobs as $job){switch($job->step){case self::STEP_WRITE_REQUEST_HEADERS:self::writeRequestHeaders($job);break;case self::STEP_READ_RESPONSE_HEADERS:self::readResponseHeaders($job);break;case self::STEP_READ_RESPONSE_CHUNKED_SIZE:self::readResponseChunkedSize($job);break;case self::STEP_READ_RESPONSE_LONGED:if(null!==$result=self::readResponseLonged($job)){$results[]=$job->request->setResponse($result);}break;case self::STEP_READ_RESPONSE_CHUNKED_CONTENT:if(null!==$result=self::readResponseChunkedContent($job)){$results[]=$job->request->setResponse($result);}}}return $results;}private static function initialize(stdClass $job=null){if($job===null){$job=new stdClass;}$job->step=self::STEP_FINISHED;$job->fp=false;$job->size='';$job->buffer='';$job->incomplete='';$job->length=0;$job->info=array();return $job;}private static function connect(stdClass $job){switch(true){case!$fp=self::createSocket($job->request->host):case!stream_set_blocking($fp,0):throw new TwistException("Failed to connect: {$job->request->host}",0,$job->request);default:$job->step=self::STEP_WRITE_REQUEST_HEADERS;$job->fp=$fp;}return $job;}private static function createSocket($host){static $flag;if($flag===null){$flag=PHP_OS==='WINNT'||version_compare(PHP_VERSION,'5.3.1')<0;}return $flag?@fsockopen("ssl://{$host}",443):@stream_socket_client("ssl://{$host}:443",$dummy,$dummy,0,6);}private static function writeRequestHeaders(stdClass $job){if($job->buffer===''){$job->buffer=$job->request->buildHeaders();}if(false!==$tmp=fwrite($job->fp,$job->buffer)){$job->buffer=(string)substr($job->buffer,$tmp);}if($job->buffer===''){$job->step=$job->request->waitResponse?self::STEP_READ_RESPONSE_HEADERS:self::STEP_FINISHED;}$job->request->credential->setHistory($job->request->endpoint,isset($job->request->credential->history[$job->request->endpoint])?$job->request->credential->history[$job->request->endpoint]+1:1);return;}private static function readResponseHeaders(stdClass $job){if(is_string($buffers=self::freadUntilSeparator($job->fp,$job->buffer,"\r\n\r\n"))){$job->buffer=$buffers;return;}foreach(explode("\r\n",$buffers[0])as $i=>$line){self::readResponseHeader($job,$i,$line);}if(!empty($job->info['content-length'])){$job->step=self::STEP_READ_RESPONSE_LONGED;$job->buffer=$buffers[1];$job->length=$job->info['content-length'];}elseif(isset($job->info['transfer-encoding'])){$job->step=self::STEP_READ_RESPONSE_CHUNKED_SIZE;$job->buffer='';$job->length=0;$job->size=$buffers[1];}else{throw new TwistException('Detected malformed response header.',(int)$job->info['code'],$job->request);}}private static function readResponseLonged(stdClass $job){if(is_string($buffers=self::freadUntilLength($job->fp,$job->buffer,$job->length))){$job->buffer=$buffers;return;}$job->step=self::STEP_FINISHED;if(isset($job->info['content-encoding'])){$buffers[0]=gzinflate(substr($buffers[0],10,-8));}return self::decode($job,$buffers[0]);}private static function readResponseChunkedSize(stdClass $job){if(is_string($buffers=self::freadUntilSeparator($job->fp,$job->size,"\r\n"))){$job->size=$buffers;return;}switch(true){case $buffers[0]==='0':$job->step=self::STEP_FINISHED;$job->buffer='';$job->size='';$job->incomplete='';$job->length=0;return;case $buffers[0]==='':case 2===$tmp=hexdec($buffers[0])+2:throw new TwistException('Detected malformed response body.',(int)$job->info['code'],$job->request);default:$job->step=self::STEP_READ_RESPONSE_CHUNKED_CONTENT;$job->buffer=$buffers[1];$job->size='';$job->length=$tmp;}}private static function readResponseChunkedContent(stdClass $job){if(is_string($buffers=self::freadUntilLength($job->fp,$job->buffer,$job->length))){$job->buffer=$buffers;return;}$buffers[0]=substr($buffers[0],0,-2);$job->buffer='';$job->length=0;$job->step=self::STEP_READ_RESPONSE_CHUNKED_SIZE;if(substr($buffers[0],-1)==="\n"){$value=$job->incomplete.$buffers[0];$job->size=$buffers[1];$job->incomplete='';return preg_match('/\A\s*+\z/',$buffers[0])?null:self::decode($job,$value);}$job->size+=$buffers[1];$job->incomplete.=$buffers[0];return;}private static function readResponseHeader(stdClass $job,$offset,$line){if($offset){list($key,$value)=explode(': ',$line,2)+array(1=>'');$key=strtolower($key);switch($key){case 'set-cookie':list($k,$v)=explode('=',$line,2)+array(1=>'');list($v)=explode(";",$v);$job->request->credential->setCookie(urldecode($k),urldecode($v));break;default:$job->info[$key]=trim($value,'"');}}else{list($protocol,$code,$message)=explode(' ',$line,3)+array(1=>'0',2=>'');$job->info+=compact('protocol','code','memssage');}}private static function freadUntilSeparator($fp,$buffer,$separator){while(true){$items=explode($separator,$buffer,2);if(isset($items[1])){return $items;}if(isset($retry)){return $buffer;}$buffer.=$tmp=fread($fp,8192);$retry=true;}}private static function freadUntilLength($fp,$buffer,$length){while(true){if($length<=strlen($buffer)){return array((string)substr($buffer,0,$length),(string)substr($buffer,$length));}if(isset($retry)){return $buffer;}$buffer.=$tmp=fread($fp,8192);$retry=true;}}private static function decode(stdClass $job,$value){if(in_array($job->request->endpoint,array('/oauth/authorize','/oauth/authenticate'),true)){$object=self::decodeScraping($job,$value);}else{$object=self::decodeNormal($job,$value);}if($object instanceof TwistException and $job->request->throw){throw $object;}if($job->request->login and $job->request->endpoint!=='/oauth/access_token'){$job->request->proceed();self::initialize($job);self::connect($job);return null;}return $object;}private static function decodeNormal(stdClass $job,$value){switch(true){case null!==$object=json_decode($value):case false!==$object=json_decode(json_encode(@simplexml_load_string($value))):case!parse_str($value,$object)and $object=(object)$object and isset($object->oauth_token,$object->oauth_token_secret):case preg_match("@<title>Error \d++ ([^<]++)</title>@",$value,$m)and $object=(object)array('error'=>$m[1]):case!isset($job->info['content-type'])||$job->info['content-type']!=='application/json'and $object=(object)array('error'=>trim(strip_tags($value))):case $object=(object)array('error'=>"Unknown error on parsing: {$value}"):}if(isset($object->oauth_token,$object->oauth_token_secret)){if(isset($object->screen_name,$object->user_id)){$job->request->credential->setScreenName($object->screen_name);$job->request->credential->setUserId($object->user_id);}if($job->request->endpoint==='/oauth/request_token'){$job->request->credential->setRequestToken($object->oauth_token,$object->oauth_token_secret);}if($job->request->endpoint==='/oauth/access_token'){$job->request->credential->setAccessToken($object->oauth_token,$object->oauth_token_secret);}}if($job->request->endpoint==='/1.1/account/verify_credentials.json'){if(isset($object->screen_name,$object->id_str)){$job->request->credential->setScreenName($object->screen_name);$job->request->credential->setUserId($object->id_str);}}if(isset($job->info['x-twitter-new-account-oauth-access-token'],$job->info['x-twitter-new-account-oauth-secret'])){$object->oauth_token=$job->info['x-twitter-new-account-oauth-access-token'];$object->oauth_token_secret=$job->info['x-twitter-new-account-oauth-secret'];}switch(true){case isset($object->errors)and is_array($object->errors):$object->errors=$object->errors[0]->message;case isset($object->errors)and is_string($object->errors):$object->error=$object->errors;case isset($object->error):$object=new TwistException($object->error,(int)$job->info['code'],$job->request);}return $object;}private static function decodeScraping(stdClass $job,$value){if($job->request->method==='GET'){$pattern='@<input name="authenticity_token" type="hidden" value="([^"]++)" />@';if(!preg_match($pattern,$value,$matches)){return new TwistException('Failed to fetch authenticity_token.',(int)$job->info['code'],$job->request);}$job->request->credential->setAuthenticityToken($matches[1]);return (object)array('authenticity_token'=>$matches[1]);}else{$pattern='@oauth_verifier=([^"]++)"|<code>([^<]++)</code>@';if(!preg_match($pattern,$value,$matches)){return new TwistException('Wrong screenName or password.',(int)$job->info['code'],$job->request);}$match=implode('',array_slice($matches,1));$job->request->credential->setVerifier($match);return (object)array('oauth_verifier'=>$match);}}private function setRequest(TwistRequest $request){$job=self::initialize();$job->request=$request;$this->jobs[]=$job;}}class TwistIterator extends TwistExecuter implements Iterator{private $responses=array();final public function rewind(){$this->responses=array();return $this->start();}final public function valid(){if(false!==$tmp=current($this->responses)){return true;}$this->responses=array();while(!$this->responses and $this->isRunning()){$this->responses=$this->run();}return (bool)$this->responses;}final public function key(){return '';}final public function current(){return current($this->responses);}final public function next(){next($this->responses);return $this;}}class TwistRequest extends TwistBase{private $host;private $endpoint;private $method;private $extraParams;private $streaming;private $multipart;private $waitResponse;private $throw;private $login=false;private $credential;private $params=array();private $response;final public static function get($endpoint='',$params=array(),TwistCredential $credential=null){$args=get_defined_vars();$args+=array('method'=>'GET','waitResponse'=>true,'throw'=>false,);return new self($args);}final public static function getAuto($endpoint='',$params=array(),TwistCredential $credential=null){$args=get_defined_vars();$args+=array('method'=>'GET','waitResponse'=>true,'throw'=>true,);return new self($args);}final public static function post($endpoint='',$params=array(),TwistCredential $credential=null){$args=get_defined_vars();$args+=array('method'=>'POST','waitResponse'=>true,'throw'=>false,);return new self($args);}final public static function postAuto($endpoint='',$params=array(),TwistCredential $credential=null){$args=get_defined_vars();$args+=array('method'=>'POST','waitResponse'=>true,'throw'=>true,);return new self($args);}final public static function send($endpoint='',$params=array(),TwistCredential $credential=null){$args=get_defined_vars();$args+=array('method'=>'POST','waitResponse'=>false,'throw'=>false,);return new self($args);}final public static function login(TwistCredential $credential){$args=array('endpoint'=>'oauth/request_token','method'=>'POST','params'=>array(),'credential'=>$credential,'waitResponse'=>true,'throw'=>true,);$self=new self($args);$self->login=true;return $self;}final public function __get($name){if(!property_exists($this,$name=self::filter($name))){throw new OutOfRangeException("Invalid property name: {$name}");}return $this->$name;}final public function setParams($params=array()){if($this->login){throw new BadMethodCallException('This object is created by TwistRequest::login() call.');}$this->params=is_array($params)?self::filter($params,1):self::parseQuery(self::filter($params));return $this;}final public function setCredential(TwistCredential $credential=null){if($this->login){throw new BadMethodCallException('This object is created by TwistRequest::login() call.');}$this->credential=$credential;return $this;}final public function setResponse($body=null){$this->response=$body;return $this;}final public function execute(){foreach(new TwistIterator($this)as $request){return $request;}}final public function proceed(){switch(true){case!$this->login:throw new BadMethodCallException('This object is not created by TwistRequest::login() call.');case $this->response instanceof TwistException:case $this->endpoint==='/oauth/access_token':$args=array('method'=>'POST','endpoint'=>'/oauth/request_token',);break;case $this->endpoint==='/oauth/request_token':$args=array('method'=>'GET','endpoint'=>'/oauth/authorize',);break;case $this->endpoint==='/oauth/authorize'and $this->method==='GET':$args=array('method'=>'POST','endpoint'=>'/oauth/authorize',);break;case $this->endpoint==='/oauth/authorize'and $this->method==='POST':$args=array('method'=>'POST','endpoint'=>'/oauth/access_token',);break;default:throw new BsdMethodCallException('Unexpected endpoint.');}$args+=array('params'=>array(),'credential'=>$this->credential,'waitResponse'=>true,'throw'=>true,);$this->__construct($args);return $this;}final public function buildHeaders(){if(!($this->credential instanceof TwistCredential)){throw new BadMethodCallException('Headers cannot be built without TwistCredential instance.');}$params=$this->solveParams();$connection=$this->streaming?'keep-alive':'close';$user_agent=urlencode($this->credential->userAgent);$content=$this->buildOAuthPart($params);if($this->method==='GET'){if(''!==$query=self::buildQuery($params)){$content.="&{$query}";}$lines=array("{$this->method} {$this->endpoint}?{$content} HTTP/1.1","Host: {$this->host}","User-Agent: {$user_agent}","Connection: {$connection}","","",);}elseif(!$this->multipart){if(''!==$query=self::buildQuery($params)){$content.="&{$query}";}$length=strlen($content);$lines=array("{$this->method} {$this->endpoint} HTTP/1.1","Host: {$this->host}","User-Agent: {$user_agent}","Connection: {$connection}","Content-Type: application/x-www-form-urlencoded","Content-Length: {$length}","",$content,);}else{$boundary='--------------------'.sha1(mt_rand().microtime());$authorization=implode(', ',explode('&',$content));$content=self::buildMultipartContent($params,$boundary);$length=strlen($content);$lines=array("{$this->method} {$this->endpoint} HTTP/1.1","Host: {$this->host}","User-Agent: {$user_agent}","Connection: {$connection}","Authorization: OAuth {$authorization}","Content-Type: multipart/form-data; boundary={$boundary}","Content-Length: {$length}","",$content,);}if(!$this->streaming){array_splice($lines,3,0,"Accept-Encoding: deflate, gzip");}if($this->credential->cookies){$cookie=http_build_query($this->credential->cookies,'','; ');array_splice($lines,3,0,"Cookie: {$cookie}");}return implode("\r\n",$lines);}private static function buildMultipartContent(array $params,$boundary){$lines=array();foreach($params as $key=>$value){if($key==='media[]'){$filename=md5(mt_rand().microtime());$disposition="form-data; name=\"{$key}\"; filename=\"{$filename}\"";}else{$disposition="form-data; name=\"{$key}\"";}array_push($lines,"--{$boundary}","Content-Disposition: {$disposition}","Content-Type: application/octet-stream","",$value);}$lines[]="--{$boundary}--";return implode("\r\n",$lines);}private static function buildQuery(array $params,$pair=true){$new=array();foreach($params as $key=>$value){$value=str_replace('%7E','~',rawurlencode($value));$new[$key]=$pair?"{$key}={$value}":$value;}uksort($new,'strnatcmp');return implode('&',$new);}private static function parseQuery($query){foreach(explode('&',$query)as $pair){list($k,$v)=explode('=',$pair,2)+array(1=>'');$params[$k]=$v;}if($params===array(''=>'')){$params=array();}return $params;}private function __construct(array $args){$this->params=is_array($args['params'])?self::filter($args['params'],1):self::parseQuery(self::filter($args['params']));$this->credential=$args['credential'];$this->setEndpoint($args['endpoint']);$this->method=$args['method'];$this->waitResponse=$args['waitResponse'];$this->throw=$args['throw'];}private function setEndpoint($endpoint){static $streamings=array('filter','sample','firehose');$endpoint=self::filter($endpoint);switch(true){case!$p=parse_url($endpoint):case!isset($p['path']):case!$count=preg_match_all('/(?![\d.])[\w.]++/',$p['path'],$parts):throw new InvalidArgumentException("invalid endpoint: {$endpoint}");}$streaming=$multipart=$old=!$host='api.twitter.com';foreach($parts[0]as $i=>&$part){$part=strtolower($part);if($count===$i+1){switch(true){case $parts[0][0]==='oauth2':throw new InvalidArgumentException("this library does not support OAuth 2.0 authentication");case $parts[0][0]==='oauth':$part=basename($part,'.json');break 2;case $old=$parts[0][0]==='urls'and $host='urls.api.twitter.com':case $old=$parts[0][0]==='generate':case $streaming=$parts[0][0]==='user'and $host='userstream.twitter.com':case $streaming=$parts[0][0]==='site'and $host='sitestream.twitter.com':case $streaming=in_array($part,$streamings)and $host='stream.twitter.com':default:$multipart=$part==='update_with_media';$part=basename($part,'.json').'.json';array_splice($parts[0],0,0,$old?'1':'1.1');break 2;}}}$this->host=$host;$this->endpoint='/'.implode('/',$parts[0]);$this->extraParams=isset($p['query'])?self::parseQuery($p['query']):array();$this->streaming=$streaming;$this->multipart=$multipart;return $this;}private function solveParams(){$new=array();$params=$this->params+$this->extraParams;foreach($params as $key=>$value){if($value===null){continue;}if($value===false){$value='0';}$value=self::filter($value);if(strpos($key,'@')===0){if(!is_readable($value)or!is_file($value)){throw new InvalidArgumentException("File not found: {$value}");}$key=(string)substr($key,1);$value=file_get_contents($value);if(!$this->multipart){$value=base64_encode($value);}}$new[$key]=$value;}return $new;}private function buildOAuthPart(array $params){if(in_array($this->endpoint,array('/oauth/authorize','/oauth/authenticate'),true)){$bodies['oauth_token']=$this->credential->requestToken;$bodies['force_login']='1';if($this->method==='POST'){$bodies['authenticity_token']=$this->credential->authenticityToken;$bodies['session[username_or_email]']=$this->credential->screenName;$bodies['session[password]']=$this->credential->password;}return self::buildQuery($bodies);}$bodies=array('oauth_consumer_key'=>$this->credential->consumerKey,'oauth_signature_method'=>'HMAC-SHA1','oauth_timestamp'=>time(),'oauth_version'=>'1.0a','oauth_nonce'=>sha1(mt_rand().microtime()),);$keys=array($this->credential->consumerSecret,'');if($this->endpoint==='/oauth/access_token'){$bodies['oauth_token']=$this->credential->requestToken;$bodies['oauth_verifier']=$this->credential->verifier;$keys[1]=$this->credential->requestTokenSecret;}elseif($this->endpoint!=='/oauth/request_token'){$bodies['oauth_token']=$this->credential->accessToken;$keys[1]=$this->credential->accessTokenSecret;}$copy=$bodies;if(!$this->multipart){$copy+=$params;}$url="https://{$this->host}{$this->endpoint}";$copy=self::buildQuery(array($this->method,$url,self::buildQuery($copy)),false);$keys=self::buildQuery($keys,false);$bodies['oauth_signature']=base64_encode(hash_hmac('sha1',$copy,$keys,true));return self::buildQuery($bodies);}}abstract class TwistUnserializable{final public function __sleep(){throw new BadMethodCallException('This object cannot be serialized.');}final public function __wakeup(){throw new BadMethodCallException('This serial cannot be unserialized.');}}