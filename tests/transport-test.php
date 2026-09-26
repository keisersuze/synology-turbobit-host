<?php
require __DIR__ . '/test.php';
class TransportCheck extends SynoFileHostingTurboBit {
    public function get($url,$probe=false) { return $this->request($url,'GET',null,'https://turbobit.net/test.html',false,$probe); }
}
$h=new TransportCheck('https://trbt.cc/test.html','','',array());
$base='http://127.0.0.1:'.$argv[1];
$h->get($base.'/set-cookie');
$r=$h->get($base.'/only/echo');$d=json_decode($r['body'],true);
check($d['cookie']==='fixture_session=synthetic-value','cookie persists in cURL engine');
check($d['host']==='127.0.0.1:'.$argv[1],'no forced Host header');
check($d['referer']==='https://turbobit.net/test.html','referer sent');
$r=$h->get($base.'/elsewhere');$d=json_decode($r['body'],true);
check($d['cookie']==='','cookie path respected');
$r=$h->get('http://localhost:'.$argv[1].'/only/echo');$d=json_decode($r['body'],true);
check($d['cookie']==='','cookie domain respected');
$r=$h->get($base.'/large',true);
check(strlen($r['body'])===1024 && $r['errno']===0 && $r['code']===200,'bounded probe when Range ignored');
$r=$h->get($base.'/echo');$d=json_decode($r['body'],true);
check($d['range']===null,'Range reset after probe');
$r=$h->get($base.'/reference.bin');
$expected='';for($i=0;$i<256;$i++) $expected.=chr($i);$expected=str_repeat($expected,16);
check($r['code']===200 && hash('sha256',$r['body'])===hash('sha256',$expected),'complete reference file integrity');
$r=$h->get($base.'/reference.bin',true);
check($r['code']===206 && $r['headers']['content-range']==='bytes 0-1023/4096' && $r['body']===substr($expected,0,1024),'actual range bytes and headers');
$other=new TransportCheck('https://trbt.cc/test.html','','',array());
$r=$other->get($base.'/only/echo');$d=json_decode($r['body'],true);
check($d['cookie']==='', 'independent sessions never share cookies');
unset($other);
unset($h);
echo "PASS: 10 real cURL transport checks\n";
