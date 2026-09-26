<?php
require __DIR__ . '/test.php';
$root=sys_get_temp_dir().'/turbobit-log-test-'.uniqid();mkdir($root,0700);
define('TEST_LOG_DIR',$root);
class LogHost extends SynoFileHostingTurboBit {
    const LOG_DIR = TEST_LOG_DIR;
    public function emit($label,$value) { $this->log($label,$value); }
}
try {
    for($i=0;$i<102;$i++) { file_put_contents($root.'/old'.$i.'.log','old');touch($root.'/old'.$i.'.log',time()-8*86400); }
    $h=new LogHost('https://trbt.cc/example12345.html','fixture@example.invalid;local_log=1','fixture-password',array());
    $h->emit('FILENAME','private-document.txt');
    $h->emit('FINAL DOWNLOAD URL','https://cdn.example.invalid/private-path?secret=fixture-password');
    $h->emit('ERROR','fixture@example.invalid fixture-password');
    $path=$root.'/example12345.log';$body=file_get_contents($path);
    check(count(glob($root.'/*.log'))===1,'expired logs retained within count budget');
    check((fileperms($path)&0777)===0600,'log restricted permissions');
    check(!preg_match('/private-document|private-path|fixture-password|fixture@example/',$body),'written log hides personal data');
    check(preg_match('/\[[0-9a-f]{12}\] MODULE VERSION: 1.0.6/',$body)===1,'execution identifier');
    file_put_contents($path,str_repeat('x',2097153));
    $h->emit('RESULT','DOWNLOAD_READY');clearstatcache(true,$path);
    check(filesize($path)<1024 && strpos(file_get_contents($path),'DOWNLOAD_READY')!==false,'oversized log rotated under lock');
    echo "PASS: 5 real log checks\n";
} finally {
    foreach(glob($root.'/*') as $file) unlink($file);
    rmdir($root);
}
