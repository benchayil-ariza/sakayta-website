<?php
// driverVerification.php
// This is your SOAP service, written in PHP - a different stack from your Node.js REST API.
// Run it with: php -S localhost:8080 driverVerification.php

ini_set("soap.wsdl_cache_enabled", "0");

// In a real system this would be a real driver/license registry.
// For this prototype, it's a small hardcoded list matching the driver IDs
// seeded in the Node.js database (d1, d2).
$driverRegistry = [
    'd1' => ['licenseNumber' => 'N01-23-456789', 'verified' => true],
    'd2' => ['licenseNumber' => 'N02-98-765432', 'verified' => true],
];

class DriverVerificationService {
    private $registry;

    public function __construct($registry) {
        $this->registry = $registry;
    }

    public function VerifyDriver($driverId) {
        if (isset($this->registry[$driverId])) {
            $record = $this->registry[$driverId];
            return [
                'verified' => $record['verified'],
                'licenseNumber' => $record['licenseNumber'],
            ];
        }
        return ['verified' => false, 'licenseNumber' => ''];
    }
}

// Serve the WSDL file when requested with ?wsdl
if (isset($_GET['wsdl'])) {
    header('Content-Type: text/xml');
    readfile(__DIR__ . '/driverVerification.wsdl');
    exit;
}

$server = new SoapServer(__DIR__ . '/driverVerification.wsdl', ['cache_wsdl' => WSDL_CACHE_NONE]);
$server->setObject(new DriverVerificationService($driverRegistry));
$server->handle();
