<?php
error_reporting(E_ALL);
set_error_handler(function($level, $message, $file, $line) {
    if (error_reporting() & $level) throw new Exception($message . ' at ' . $file . ':' . $line);
});
foreach (array('LOGIN_FAIL'=>4, 'USER_IS_FREE'=>5, 'USER_IS_PREMIUM'=>6, 'ERR_UNKNOWN'=>1, 'ERR_FILE_NO_EXIST'=>114, 'ERR_REQUIRED_PREMIUM'=>115, 'ERR_NOT_SUPPORT_TYPE'=>116, 'DOWNLOAD_URL'=>'downloadurl', 'DOWNLOAD_FILENAME'=>'filename', 'DOWNLOAD_ISPARALLELDOWNLOAD'=>'isparalleldownload', 'DOWNLOAD_COOKIE'=>'cookiepath', 'DOWNLOAD_ERROR'=>'error', 'INFO_NAME'=>'name') as $k=>$v) define($k,$v);
require __DIR__ . '/../src/TurboBitOrg.php';
$checks = 0;
function check($ok, $message) { global $checks; $checks++; if (!$ok) throw new Exception('FAIL: ' . $message); }
function response($body='', $code=200, $headers=array()) { return array('body'=>$body, 'code'=>$code, 'headers'=>$headers, 'error'=>'', 'errno'=>0); }
function binaryResponse() { return response('bytes',206,array('content-type'=>'application/octet-stream','content-range'=>'bytes 0-4/5','content-length'=>'5')); }
class FixtureHost extends SynoFileHostingTurboBit {
    public $queue = array(); public $seen = array(); public $logs = array();
    protected function request($url, $method='GET', $data=null, $referer='', $json=false, $probe=false) {
        $this->seen[] = array($url,$method,$data,$referer,$json,$probe);
        if (!$this->queue) throw new Exception('Unexpected request: '.$url);
        $r=array_shift($this->queue);$r['url']=$url;return $r;
    }
    protected function log($label,$value) { $this->logs[$label]=$this->redact($label,$value); }
    public function absolute($base,$rel) { return $this->absoluteUrl($base,$rel); }
    public function link($html,$base) { return $this->premiumLink($html,$base); }
    public function form($html,$base) { return $this->loginForm($html,$base); }
    public function filename($r) { return $this->filenameFromResponse($r); }
    public function fileResponse($r,$size=null) { $this->fileSize=$size; return $this->isFileResponse($r); }
    public function redirects($url,$method='GET',$data=null,$probe=false) { return $this->follow($url,'REDIRECT',$method,$data,'',false,$probe); }
    public function scrub($label,$value) { return $this->redact($label,$value); }
    public function normalized() {
        $p=new ReflectionProperty('SynoFileHostingTurboBit','url');
        if (PHP_VERSION_ID < 80100) $p->setAccessible(true);
        return $p->getValue($this);
    }
}
function host($url='https://trbt.cc/example12345.html') { return new FixtureHost($url,'fixture@example.invalid','fixture-password',array()); }
function apiQueue($premium=true) { return array(response('<html><div id="app"></div></html>'),response('{}'),response(json_encode(array('premium'=>array('status'=>$premium?'active':'inactive'))))); }
function fileInfo($links=null,$size='5',$name='original.zip') {
    if ($links===null) $links=array('https://turbobit.net/download/redirect/token/example12345');
    return response(json_encode(array('premium'=>true,'file'=>array('id'=>'example12345','name'=>$name,'size'=>$size),'downloadUrls'=>$links)));
}
function expectFailure($h,$reason) {
    $r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_ERROR]) && isset($h->logs['RESULT']) && $h->logs['RESULT']==='FAILED: '.$reason,$reason);
}
$canonical='https://turbobit.net/example12345.html';
foreach (array('turbobit.net','www.turbobit.net','trbt.cc','www.trbt.cc','torbobit.net','www.torbobit.net') as $domain) {
    foreach (array('http','https') as $scheme) {
        check(host(' '.$scheme.'://'.strtoupper($domain).'/example12345.html?track=x#fragment ')->normalized()===$canonical,'canonical '.$scheme.' '.$domain);
        check(host($scheme.'://'.$domain.'/download/redirect/expired-token/example12345?sig=abc')->normalized()===$canonical,'renew direct input '.$domain);
    }
}
check(host('https://turbobit.net:443/example12345/a.zip.html')->normalized()===$canonical,'explicit default port and filename');
foreach (array('https://turbobit.net.evil.invalid/example12345.html','https://u:p@turbobit.net/example12345.html','ftp://turbobit.net/example12345.html','https://turbobit.net:444/example12345.html','https://turbobit.net/example12345','https://turbobit.net/download/redirect/opaque') as $url) {
    $h=host($url);check($h->GetDownloadInfo()[DOWNLOAD_ERROR]===ERR_NOT_SUPPORT_TYPE && !$h->seen,'invalid input before login');
}
$h=host();
foreach (array('/download/redirect/token/id'=>'https://turbobit.net/download/redirect/token/id','//turbobit.net/x'=>'https://turbobit.net/x','../x?y=1&amp;z=2'=>'https://turbobit.net/x?y=1&amp;z=2','?q=2'=>'https://turbobit.net/dir/file?q=2','../../../x'=>'https://turbobit.net/x','..'=>'https://turbobit.net/','/a//b/../c'=>'https://turbobit.net/a//c','/a/.'=>'https://turbobit.net/a/','/x%2Fz?sig=%26amp%3B'=>'https://turbobit.net/x%2Fz?sig=%26amp%3B','#part'=>'https://turbobit.net/dir/file') as $rel=>$expected) check($h->absolute('https://turbobit.net/dir/file',$rel)===$expected,'resolve '.$rel);
check($h->link('<a href="/download/redirect/token/id?a=1&amp;b=2">Télécharger</a>','https://turbobit.net/id.html')==='https://turbobit.net/download/redirect/token/id?a=1&b=2','DOM decodes HTML once');
check($h->link('<a href="https://evil.invalid/download/redirect/token/id">Download file</a>','https://turbobit.net/id.html')===null,'foreign premium anchor rejected');
$f=$h->form('<form action="/user/login"><input type="hidden" name="csrf" value="csrf-fixture"><input name="user[login]"><input type="password" name="user[pass]"></form>','https://turbobit.net/login');
check($f[0]==='https://turbobit.net/user/login' && $f[1]['csrf']==='csrf-fixture' && $f[1]['user[login]']==='fixture@example.invalid','form and CSRF');
foreach (array('attachment; filename="file.zip"'=>'file.zip','attachment; filename=file.zip'=>'file.zip',"attachment; filename*=UTF-8''caf%C3%A9.zip"=>'café.zip') as $cd=>$name) check($h->filename(array('url'=>'https://cdn.example.invalid/f','headers'=>array('content-disposition'=>$cd)))===$name,'filename syntax');
check(!$h->fileResponse(response('<html>login</html>',200,array('content-type'=>'text/html','content-disposition'=>'attachment; filename="error.html"'))),'attachment does not override HTML error');
check($h->fileResponse(binaryResponse()),'valid partial binary');
foreach (array('text/plain','text/html','application/json','application/xml','') as $type) check($h->fileResponse(response('hello',200,array('content-type'=>$type,'content-length'=>'5')),'5'),'metadata supports '.$type);
check(!$h->fileResponse(response('hello',200,array('content-type'=>'text/plain','content-length'=>'5')),'50'),'size mismatch rejected');
check($h->fileResponse(response('',200,array('content-length'=>'0')),'0'),'empty file confirmed by metadata');
check(!$h->fileResponse(response('',200,array('content-type'=>'application/octet-stream'))),'unknown empty response rejected');
check(!$h->fileResponse(response('bytes',206,array('content-type'=>'application/octet-stream'))),'206 missing range rejected');
foreach (array('bytes 1-5/6','bytes 0-5/5','bytes 0-4/*','bytes 0-9999/10000') as $range) check(!$h->fileResponse(response('bytes',206,array('content-type'=>'application/zip','content-range'=>$range))),'invalid range '.$range);
check($h->fileResponse(response('bytes',206,array('content-type'=>'application/zip','content-range'=>'bytes 0-4/7000000000')),'7000000000'),'large size remains decimal string');
foreach (array(301,302,303,307,308) as $code) {
 $h=host();$h->queue=array(response('',$code,array('location'=>'/next')),binaryResponse());$r=$h->redirects('https://turbobit.net/start');check($r['url']==='https://turbobit.net/next','redirect '.$code);
}
$h=host();$h->queue=array(response('',302,array('location'=>'/done')),response('ok'));$h->redirects('https://turbobit.net/start','POST',array('password'=>'fixture-password'));check($h->seen[1][1]==='GET' && $h->seen[1][2]===null,'POST 302 becomes GET');
foreach (array('https://cdn.example.invalid/f','http://turbobit.net/f','https://turbobit.net:444/f') as $next) {
 $h=host();$h->queue=array(response('',307,array('location'=>$next)));
 try { $h->redirects('https://turbobit.net/start','POST',array('password'=>'fixture-password'));check(false,'POST leak'); } catch(TurboBitOrgException $e) { check($e->getMessage()==='CROSS_ORIGIN_POST_REDIRECT','origin includes scheme and port'); }
}
$h=host();$h->queue=array(response('',302,array('location'=>'/start')));try { $h->redirects('https://turbobit.net/start');check(false,'loop'); } catch(TurboBitOrgException $e) { check($e->getMessage()==='REDIRECT_LOOP','loop detection'); }
$h=host();$h->queue=apiQueue();$h->queue[]=fileInfo();$h->queue[]=response('',302,array('location'=>'https://cdn.example.invalid/signed-file'));$h->queue[]=binaryResponse();
$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]) && $r[DOWNLOAD_URL]==='https://cdn.example.invalid/signed-file','complete API flow');check($r[DOWNLOAD_FILENAME]==='original.zip','API filename fallback');check($h->seen[1][0]==='https://app.turbobit.net/api/auth/login','login endpoint unchanged');check($h->seen[3][0]==='https://app.turbobit.net/api/download/info','no redundant HTML file request');check($h->seen[4][3]==='' && $h->seen[5][3]==='','no unsupported Referer');check(!$h->queue && count($h->seen)===6,'no second CDN probe');check($r[DOWNLOAD_ISPARALLELDOWNLOAD]===false,'parallel stays disabled');
$h=host('https://torbobit.net/download/redirect/expired/example12345');$h->queue=apiQueue();$h->queue[]=fileInfo(array('https://cdn.example.invalid/new'));$h->queue[]=binaryResponse();$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]) && $h->seen[3][2]['fileId']==='example12345','direct input renews through API');
$h=host();$h->queue=apiQueue(false);check($h->GetDownloadInfo()[DOWNLOAD_ERROR]===ERR_REQUIRED_PREMIUM,'free account rejected');
$h=host();$h->queue=apiQueue();check($h->Verify(true)===USER_IS_PREMIUM,'premium without client login field');
foreach (array('{"message":"Unauthenticated."}','{"premium":{"status":"unexpected"}}') as $body) { $h=host();$h->queue=array(response('<div id="app"></div>'),response('{}'),response($body));check($h->Verify(true)===LOGIN_FAIL,'ambiguous account rejected'); }
foreach (array('password_incorrect'=>'INVALID_CREDENTIALS','invalid_captcha'=>'CAPTCHA_REQUIRED','traffic_exceeded'=>'QUOTA_EXCEEDED') as $name=>$reason) { $h=host();$h->queue=array(response('<div id="app"></div>'),response(json_encode(array('error_name'=>$name)),422));expectFailure($h,$reason);check(count($h->seen)===2,'no repeated login'); }
$h=host();$h->queue=array(response('<div id="app"></div>'),response('{"needCaptcha":true}',200));expectFailure($h,'CAPTCHA_REQUIRED');
$h=host();$h->queue=apiQueue();$h->queue[]=response('{"error_name":"file_not_found"}',404);check($h->GetDownloadInfo()[DOWNLOAD_ERROR]===ERR_FILE_NO_EXIST,'missing file API');
$h=host();$h->queue=apiQueue();$h->queue[]=response('{"premium":"false","file":{},"downloadUrls":[]}');expectFailure($h,'INVALID_FILE_API_SCHEMA');
$h=host();$h->queue=apiQueue();$h->queue[]=response('{"premium":true,"file":{"id":"wrong"},"downloadUrls":[]}');expectFailure($h,'FILE_ID_MISMATCH');
$h=host();$h->queue=apiQueue();$h->queue[]=response('[]');expectFailure($h,'API_NOT_JSON_OBJECT');
$h=host();$h->queue=apiQueue();$h->queue[0]=response('unavailable',503);$h->queue[]=fileInfo();$h->queue[]=binaryResponse();$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]),'public login page outage falls back once');
$h=host();$h->queue=apiQueue();$h->queue[]=fileInfo(array('https://cdn1.example.invalid/f','https://cdn2.example.invalid/f'));$h->queue[]=response('',503);$h->queue[]=binaryResponse();$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]) && $r[DOWNLOAD_URL]==='https://cdn2.example.invalid/f','bounded alternative on outage');
$h=host();$h->queue=apiQueue();$h->queue[]=fileInfo(array('https://cdn1.example.invalid/f','https://cdn2.example.invalid/f'));$h->queue[]=response('',429);expectFailure($h,'RATE_LIMITED');check(count($h->seen)===5,'429 stops instead of switching servers');
$h=host();$h->queue=apiQueue();$h->queue[]=fileInfo(null,'0','empty.txt');$h->queue[]=response('',416);$h->queue[]=response('',200,array('content-length'=>'0'));$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]) && $h->seen[5][5]==='no-range','empty file retries without Range with body limit');
$h=host();$h->queue=array(response('<form action="/user/login"><input name="user[login]"><input type="password" name="user[pass]"></form>'),response('',302,array('location'=>'/')),response('ok'),response('<div class="user-menu"><span class="yesturbo"></span></div>'),response('<a href="/download/redirect/token/id">Fichier</a>'),response('',301,array('location'=>'https://cdn.example.invalid/file.zip')),response('binary',200,array('content-type'=>'application/zip')));
$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]) && $r[DOWNLOAD_FILENAME]==='file.zip','legacy HTML flow');
check($h->scrub('FINAL DOWNLOAD URL','https://cdn.example.invalid/secret/movie.mkv?token=secret#private')==='https://cdn.example.invalid/[REDACTED]','all signed path and fragment data masked');
check($h->scrub('LOCATION HEADER','/secret/movie.mkv?token=secret')==='[REDACTED]','relative location masked');
check($h->scrub('FILENAME','personal-file.txt')==='[REDACTED]','filename masked');
check(strpos($h->scrub('ERROR','fixture-password fixture@example.invalid'),'fixture')===false,'credentials masked');
check($h->scrub('CURL ERROR','CURL_60')==='CURL_60','curl diagnostics not mistaken for URL labels');
foreach (array('[]', '[ ]', '{}') as $ack) {
    $h=host();$h->queue=apiQueue();$h->queue[1]=response($ack);
    check($h->Verify(true)===USER_IS_PREMIUM && count($h->seen)===3,'login acknowledgement followed by premium verification');
}
$h=host();$h->queue=apiQueue(false);$h->queue[1]=response('[]');
check($h->Verify(true)===USER_IS_FREE,'empty login acknowledgement does not imply premium');
$h=host();$h->queue=array(response('<div id="app"></div>'),response('[]'),response('{"message":"Unauthenticated."}',401));
check($h->Verify(true)===LOGIN_FAIL,'empty acknowledgement followed by rejected session fails');
$h=host();$h->queue=array(response('<div id="app"></div>'),response('[]'),response('[]'));
check($h->Verify(true)===LOGIN_FAIL && $h->logs['ERROR']==='API_NOT_JSON_OBJECT','account endpoint still requires object');
foreach (array('null','true','', '<html>error</html>', '["unexpected"]') as $ack) {
    $h=host();$h->queue=array(response('<div id="app"></div>'),response($ack));
    check($h->Verify(true)===LOGIN_FAIL,'malformed acknowledgement still fails');
}
echo 'PASS: '.$checks." checks\n";
