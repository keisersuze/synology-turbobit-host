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
class FixtureHost extends SynoFileHostingTurboBit {
    public $queue = array(); public $seen = array();
    protected function request($url, $method='GET', $data=null, $referer='', $json=false, $probe=false) {
        $this->seen[] = array($url,$method,$data,$referer,$json,$probe);
        if (!$this->queue) throw new Exception('Unexpected request: '.$url);
        $r=array_shift($this->queue);$r['url']=$url;return $r;
    }
    public function absolute($base,$rel) { return $this->absoluteUrl($base,$rel); }
    public function link($html,$base) { return $this->premiumLink($html,$base); }
    public function form($html,$base) { return $this->loginForm($html,$base); }
    public function filename($r) { return $this->filenameFromResponse($r); }
    public function fileResponse($r) { return $this->isFileResponse($r); }
    public function redirects($url,$method='GET',$data=null) { return $this->follow($url,'REDIRECT',$method,$data); }
}
function host($url='https://trbt.cc/example12345.html') { return new FixtureHost($url,'fixture@example.invalid','fixture-password',array()); }
function apiQueue($premium=true) { return array(response('<html><div id="app"></div></html>'),response('{}'),response(json_encode(array('premium'=>array('status'=>$premium?'active':'inactive'))))); }
$h=host();
foreach (array('/download/redirect/token/id'=>'https://turbobit.net/download/redirect/token/id','//turbobit.net/x'=>'https://turbobit.net/x','../x?y=1&amp;z=2'=>'https://turbobit.net/x?y=1&z=2','?q=2'=>'https://turbobit.net/dir/file?q=2') as $rel=>$expected) check($h->absolute('https://turbobit.net/dir/file',$rel)===$expected,'resolve '.$rel);
check($h->link('<a href="/download/redirect/token/id">Télécharger maintenant</a>','https://turbobit.net/id.html')==='https://turbobit.net/download/redirect/token/id','language independent premium link');
check($h->link('<a href="https://evil.invalid/download/redirect/token/id">Download file</a>','https://turbobit.net/id.html')===null,'reject foreign premium anchor');
$f=$h->form('<form action="/user/login"><input type="hidden" name="csrf" value="csrf-fixture"><input name="user[login]"><input type="password" name="user[pass]"></form>','https://turbobit.net/login');
check($f[0]==='https://turbobit.net/user/login' && $f[1]['csrf']==='csrf-fixture' && $f[1]['user[login]']==='fixture@example.invalid','form action and CSRF');
foreach (array('attachment; filename="file.zip"'=>'file.zip','attachment; filename=file.zip'=>'file.zip',"attachment; filename*=UTF-8''caf%C3%A9.zip"=>'café.zip') as $cd=>$name) check($h->filename(array('url'=>'https://cdn.example.invalid/f','headers'=>array('content-disposition'=>$cd)))===$name,'filename syntax');
check(!$h->fileResponse(response('<html>login</html>',200,array('content-type'=>'text/html'))),'reject HTTP 200 login page');
check($h->fileResponse(response('binary',206,array('content-type'=>'application/octet-stream'))),'accept binary partial response');
$h->queue=array(response('',302,array('location'=>'/next')),response('',307,array('location'=>'https://cdn.example.invalid/f')),response('bytes',206,array('content-type'=>'application/octet-stream')));
$r=$h->redirects('https://turbobit.net/start');check($r['url']==='https://cdn.example.invalid/f','multiple redirects');
$h=host();$h->queue=array(response('',302,array('location'=>'/done')),response('ok'));
$h->redirects('https://turbobit.net/start','POST',array('password'=>'fixture-password'));check($h->seen[1][1]==='GET' && $h->seen[1][2]===null,'POST 302 becomes GET');
$h=host();$h->queue=array(response('',307,array('location'=>'https://cdn.example.invalid/f')));
try { $h->redirects('https://turbobit.net/start','POST',array('password'=>'fixture-password'));check(false,'post leak'); } catch(TurboBitOrgException $e) { check($e->getMessage()==='CROSS_ORIGIN_POST_REDIRECT','block credential forwarding'); }
$h=host();$h->queue=array(response('',302,array('location'=>'/start')));
try { $h->redirects('https://turbobit.net/start');check(false,'loop'); } catch(TurboBitOrgException $e) { check($e->getMessage()==='REDIRECT_LOOP','loop detection'); }
$h=host();$h->queue=apiQueue();$h->queue[]=response('<html><div id="app"></div></html>');$h->queue[]=response(json_encode(array('premium'=>true,'file'=>array('name'=>'original.zip','size'=>42),'downloadUrls'=>array('https://turbobit.net/download/redirect/token/id'))));$h->queue[]=response('',302,array('location'=>'https://cdn.example.invalid/signed-file'));$h->queue[]=response('bytes',206,array('content-type'=>'application/octet-stream'));
$h->queue[]=response('bytes',206,array('content-type'=>'application/octet-stream'));
$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]) && $r[DOWNLOAD_URL]==='https://cdn.example.invalid/signed-file','complete API flow');check($r[DOWNLOAD_FILENAME]==='original.zip','API filename fallback');check($h->seen[3][0]==='https://turbobit.net/example12345.html','canonical file URL');check($h->seen[1][0]==='https://app.turbobit.net/api/auth/login','current login endpoint');check($h->seen[6][5]===true,'bounded probe enabled');check(!$h->queue,'entire successful flow consumed');
$h=host();$h->queue=apiQueue(false);check($h->GetDownloadInfo()[DOWNLOAD_ERROR]===ERR_REQUIRED_PREMIUM,'reject free account');
$h=host();$h->queue=array(response('<div id="app"></div>'),response('{"error_name":"invalid_captcha"}',422));check($h->Verify(true)===LOGIN_FAIL,'captcha is explicit failure');
$h=host();$h->queue=apiQueue();$h->queue[]=response('not found',404);check($h->GetDownloadInfo()[DOWNLOAD_ERROR]===ERR_FILE_NO_EXIST,'file removed');
$h=host('https://turbobit.net.evil.invalid/abc.html');check($h->GetDownloadInfo()[DOWNLOAD_ERROR]===ERR_NOT_SUPPORT_TYPE && !$h->seen,'reject lookalike host before login');
$h=host();$h->queue=array(response('<form action="/user/login"><input name="user[login]"><input type="password" name="user[pass]"></form>'),response('',302,array('location'=>'/')),response('ok'),response('<div class="user-menu"><span class="yesturbo"></span></div>'),response('<a href="/download/redirect/token/id">Fichier</a>'),response('',301,array('location'=>'https://cdn.example.invalid/file.zip')),response('binary',200,array('content-type'=>'application/zip')));
$h->queue[]=response('bytes',206,array('content-type'=>'application/octet-stream'));
$r=$h->GetDownloadInfo();check(isset($r[DOWNLOAD_URL]) && $r[DOWNLOAD_FILENAME]==='file.zip','complete legacy HTML flow');
$h=host();$h->queue=apiQueue();check($h->Verify(true)===USER_IS_PREMIUM,'premium status accepted without client-only login field');
$h=host();$h->queue=array(response('<div id="app"></div>'),response('{}'),response('{"message":"Unauthenticated."}'));check($h->Verify(true)===LOGIN_FAIL,'HTTP 200 alone never proves authentication');
$h=host();$h->queue=array(response('<div id="app"></div>'),response('{}'),response('{"premium":{"status":"unexpected"}}'));check($h->Verify(true)===LOGIN_FAIL,'unknown premium schema rejected');
echo 'PASS: '.$checks." checks\n";
