<?php
// Installer building blocks, stubs and config patch strings:  php tests/publisher.php
require dirname(__DIR__).'/src/Support/StubPublisher.php';
use Molefigog\Payments\Support\StubPublisher as P;
$t=0;$f=0; function check($n,$g,$e){global $t,$f;$t++; if($g!==$e){$f++; echo "FAIL $n: ".var_export($g,true)." != ".var_export($e,true)."\n";}}
$root=dirname(__DIR__); $tmp=sys_get_temp_dir().'/pubtest_'.getmypid(); mkdir($tmp);

// 1. create with placeholder replacement
$p=new P(false);
check('create', $p->file("$root/stubs/Payment.stub","$tmp/app/Models/Payment.php",['rootNamespace'=>'App\\']), 'created');
$c=file_get_contents("$tmp/app/Models/Payment.php");
check('namespace filled', str_contains($c,'namespace App\\Models;'), true);
check('no placeholder left', str_contains($c,'{{'), false);
exec('php -l '.escapeshellarg("$tmp/app/Models/Payment.php").' 2>&1',$o,$rc); check('generated model lints', $rc, 0);

// 2. never clobber user edits
file_put_contents("$tmp/app/Models/Payment.php","<?php // mine");
check('exists', $p->file("$root/stubs/Payment.stub","$tmp/app/Models/Payment.php",['rootNamespace'=>'App\\']), 'exists');
check('user edit kept', file_get_contents("$tmp/app/Models/Payment.php"), "<?php // mine");
// 3. force overwrites
check('force', (new P(true))->file("$root/stubs/Payment.stub","$tmp/app/Models/Payment.php",['rootNamespace'=>'Acme\\']), 'overwritten');
check('force content', str_contains(file_get_contents("$tmp/app/Models/Payment.php"),'namespace Acme\\Models;'), true);

// 4. every stub generates valid PHP
foreach (['Payment','PaymentGateway','HandlePaymentCompleted','AppProductResolver'] as $s) {
  $dest="$tmp/gen/$s.php"; (new P)->file("$root/stubs/$s.stub",$dest,['rootNamespace'=>'App\\']);
  $o=[]; exec('php -l '.escapeshellarg($dest).' 2>&1',$o,$rc); check("stub $s lints",$rc,0);
}

// 5. directory copy keeps structure, second run skips all
$r1=(new P)->directory("$root/resources/js","$tmp/quasar/src/payments");
check('dir files', array_map(fn($k)=>str_replace("$tmp/quasar/src/payments/",'',$k), array_keys($r1)), ['core/payments-core.js','vue/PaymentDialog.vue','vue/usePayment.js']);
check('dir all created', array_unique(array_values($r1)), ['created']);
check('vue import path intact', str_contains(file_get_contents("$tmp/quasar/src/payments/vue/usePayment.js"),"'../core/payments-core.js'"), true);
check('rerun skips', array_unique(array_values((new P)->directory("$root/resources/js","$tmp/quasar/src/payments"))), ['exists']);

// 6. path resolving (incl. Windows + relative-to-sibling)
check('relative', P::resolvePath('resources/js/payments','/srv/app'), '/srv/app/resources/js/payments');
check('sibling', P::resolvePath('../Musik-Frontend2/src/payments','/srv/app'), '/srv/app/../Musik-Frontend2/src/payments');
check('win abs', P::resolvePath('C:\\Users\\Thato\\Desktop\\front\\src','/x'), 'C:\\Users\\Thato\\Desktop\\front\\src');
check('win abs fwd', P::resolvePath('D:/code/front','/x'), 'D:/code/front');
check('unix abs', P::resolvePath('/var/www/front','/x'), '/var/www/front');

// 7. missing stub is a loud error
try { (new P)->file("$root/stubs/Nope.stub","$tmp/x.php"); check('missing',true,false);} catch(InvalidArgumentException $e){ check('missing',true,true);}

// 8. config patch strings exist verbatim in the real config, and the patched file still lints
$cfg=file_get_contents("$root/config/payments.php");
$ns='App\\';
$pairs=[
 ["'payment' => Molefigog\\Payments\\Models\\Payment::class,","'payment' => {$ns}Models\\Payment::class,"],
 ["'gateway' => Molefigog\\Payments\\Models\\PaymentGateway::class,","'gateway' => {$ns}Models\\PaymentGateway::class,"],
 ["'resolver' => null,","'resolver' => {$ns}Payments\\AppProductResolver::class,"],
];
foreach($pairs as [$a,$b]){ check("search present: $a", str_contains($cfg,$a), true); $cfg=str_replace($a,$b,$cfg); }
file_put_contents("$tmp/patched.php",$cfg); exec('php -l '.escapeshellarg("$tmp/patched.php").' 2>&1',$o,$rc); check('patched config lints',$rc,0);

exec('rm -rf '.escapeshellarg($tmp));
echo "\n$t checks, $f failed\n";
