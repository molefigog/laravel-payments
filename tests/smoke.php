<?php
// Pure-logic checks (no Laravel needed):  php tests/smoke.php
spl_autoload_register(function ($c) {
    $p = 'Molefigog\\Payments\\';
    if (str_starts_with($c, $p)) { $f = dirname(__DIR__).'/src/'.str_replace('\\','/',substr($c,strlen($p))).'.php'; if (is_file($f)) require $f; }
});
$GLOBALS['cfg'] = ['payments.resolver'=>null,'payments.allowed_redirect_hosts'=>['gw-ent.co.za'],'payments.allowed_redirect_schemes'=>['com.streama.app'],'app.url'=>'https://api.gw-ent.co.za'];
function config($k=null,$d=null){ return $GLOBALS['cfg'][$k] ?? $d; }

use Molefigog\Payments\Support\{Msisdn,ChargeRequest,RedirectGuard};
$t=0;$f=0;
function check($name,$got,$exp){ global $t,$f; $t++; if($got!==$exp){$f++; echo "FAIL $name: got ".var_export($got,true)." expected ".var_export($exp,true)."\n";} }

// Msisdn
check('intl local',  Msisdn::format('58123456','international','266'),'26658123456');
check('intl leading0',Msisdn::format('058123456','international','266'),'26658123456');
check('intl already',Msisdn::format('+266 5812 3456','international','266'),'26658123456');
check('local strip', Msisdn::format('26658123456','local','266'),'58123456');
check('digits',      Msisdn::format('+266-5812-3456','digits'),'26658123456');
check('raw',         Msisdn::format(' 58123456 ','raw'),'58123456');

// ChargeRequest ids
check('ids csv',   ChargeRequest::normalizeIds('5, 6,7'),['5','6','7']);
check('ids array', ChargeRequest::normalizeIds([5,'5',6]),['5','6']);
check('ids null',  ChargeRequest::normalizeIds(null),[]);
check('ids int',   ChargeRequest::normalizeIds(12),['12']);
$r = ChargeRequest::make('mpesa','1,2',['amount'=>'100.50','msisdn'=>'58123456','return_url'=>'https://x','email'=>'']);
check('make amount',$r->amount,100.5); check('make options',$r->options,['return_url'=>'https://x']);

// RedirectGuard
check('rg ok host',  RedirectGuard::isAllowed('https://gw-ent.co.za/paypal/result'),true);
check('rg app host', RedirectGuard::isAllowed('https://api.gw-ent.co.za/x'),true);
check('rg evil',     RedirectGuard::isAllowed('https://evil.com/'),false);
check('rg evil2',    RedirectGuard::isAllowed('https://gw-ent.co.za.evil.com/'),false);
check('rg scheme',   RedirectGuard::isAllowed('com.streama.app://paypal-callback'),true);
check('rg js',       RedirectGuard::isAllowed('javascript:alert(1)'),false);
check('rg empty',    RedirectGuard::isAllowed(''),false);
check('rg append',   RedirectGuard::append('https://a.com/r?x=1#h',['s'=>'ok']),'https://a.com/r?x=1&s=ok#h');

// Amount split (no resolver) via reflection
$svc = (new ReflectionClass(Molefigog\Payments\PaymentService::class))->newInstanceWithoutConstructor();
$m = new ReflectionMethod($svc,'resolveLines'); $m->setAccessible(true);
$lines = $m->invoke($svc, new ChargeRequest('mpesa',['1','2','3'],100.00,'5'));
check('split sum', round(array_sum(array_column($lines,'amount')),2),100.0);
check('split vals', array_column($lines,'amount'),[33.33,33.33,33.34]);
$lines = $m->invoke($svc, new ChargeRequest('mpesa',[],50.0));
check('no product', [$lines[0]['product_id'],$lines[0]['amount']],[null,50.0]);
try { $m->invoke($svc, new ChargeRequest('mpesa',['1'],null)); check('needs amount',true,false);} catch (Molefigog\Payments\Exceptions\PaymentException $e){ check('needs amount',true,true);}

// Resolver path: server price wins over client amount
$GLOBALS['cfg']['payments.resolver'] = FakeResolver::class;
class FakeResolver implements Molefigog\Payments\Contracts\ProductResolver {
  public function resolve(array $ids,array $ctx=[]): array { return array_map(fn($i)=>new Molefigog\Payments\Support\Product($i,"Song $i",25.0,7),$ids); }
}
function app($c){ return new $c; }
$lines = $m->invoke($svc, new ChargeRequest('mpesa',['9','10'],1.00));
check('resolver ignores client amount', round(array_sum(array_column($lines,'amount')),2),50.0);
check('resolver payee', $lines[0]['payee_id'],7);

// M-Pesa key encryption round-trip
$k = openssl_pkey_new(['private_key_bits'=>2048]); $det = openssl_pkey_get_details($k);
$pem = $det['key']; $b64 = trim(preg_replace('/-----[A-Z ]+-----|\s+/','',$pem));
foreach (['pem'=>$pem,'raw base64'=>$b64] as $label=>$key) {
  $c = (new ReflectionClass(Molefigog\Payments\Gateways\MpesaClient::class))->newInstanceWithoutConstructor();
  $p = new ReflectionProperty($c,'options'); $p->setAccessible(true); $p->setValue($c,['public_key'=>$key]);
  $e = new ReflectionMethod($c,'encrypt'); $e->setAccessible(true);
  $enc = $e->invoke($c,'session-123'); openssl_private_decrypt(base64_decode($enc),$dec,$k);
  check("mpesa encrypt $label",$dec,'session-123');
}
echo "\n$t checks, $f failed\n";
