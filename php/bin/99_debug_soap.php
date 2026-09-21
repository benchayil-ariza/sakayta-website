<?php declare(strict_types=1);
require __DIR__ . '/../autoload.php';
\Sakayta\Config\Config::bootstrap();

$wsdlUrl = $_ENV['SOAP_URL'] ?? 'http://localhost:8080/driverVerification.php?wsdl';

echo "=== Variant A: VerifyDriver('d1') direct scalar ===\n";
$c = new SoapClient($wsdlUrl, ['timeout' => 10, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace' => 1]);
try {
    $r = $c->VerifyDriver('d1');
    var_dump($r);
    echo "SENT: " . $c->__getLastRequest() . "\n";
} catch (SoapFault $e) { echo "FAULT: " . $e->getMessage() . "\n"; }

echo "\n=== Variant B: __soapCall('VerifyDriver', ['d1']) ===\n";
$c2 = new SoapClient($wsdlUrl, ['timeout' => 10, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace' => 1]);
try {
    $r2 = $c2->__soapCall('VerifyDriver', ['d1']);
    var_dump($r2);
    echo "SENT: " . $c2->__getLastRequest() . "\n";
} catch (SoapFault $e) { echo "FAULT: " . $e->getMessage() . "\n"; }

echo "\n=== Variant C: __soapCall('VerifyDriver', ['driverId' => 'd1']) ===\n";
$c3 = new SoapClient($wsdlUrl, ['timeout' => 10, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace' => 1]);
try {
    $r3 = $c3->__soapCall('VerifyDriver', ['driverId' => 'd1']);
    var_dump($r3);
    echo "SENT: " . $c3->__getLastRequest() . "\n";
} catch (SoapFault $e) { echo "FAULT: " . $e->getMessage() . "\n"; }

echo "\n=== Variant D: VerifyDriver(['arg' => ['driverId' => 'd1']]) ===\n";
$c4 = new SoapClient($wsdlUrl, ['timeout' => 10, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace' => 1]);
try {
    $r4 = $c4->VerifyDriver(['driverId' => 'd1']);
    var_dump($r4);
    echo "SENT: " . $c4->__getLastRequest() . "\n";
} catch (SoapFault $e) { echo "FAULT: " . $e->getMessage() . "\n"; }