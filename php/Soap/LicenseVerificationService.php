<?php declare(strict_types=1);

/**
 * SakayTa — SOAP client for driver license verification.
 *
 * Wraps the existing SOAP service to verify driver licenses.
 * Handles timeouts, connection errors, and unwraps SOAP responses.
 */

namespace Sakayta\Soap;

use SoapClient;
use SoapFault;
use RuntimeException;

final class LicenseVerificationService
{
    private string $wsdlUrl;
    private int $timeout;

    public function __construct(string $wsdlUrl = '', int $timeout = 10)
    {
        $this->wsdlUrl = $wsdlUrl ?: $_ENV['SOAP_URL'] ?? 'http://localhost/soap-service/driverVerification.php?wsdl';
        $this->timeout = $timeout;
    }

    /**
     * Verify a driver license via SOAP.
     *
     * @param string $licenseNumber The license number to verify
     * @return array{verified:bool, licenseNumber:string}
     * @throws RuntimeException on SOAP failure or timeout
     */
    public function verifyDriver(string $licenseNumber): array
    {
        $options = [
            'timeout' => $this->timeout,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ];

        try {
            $client = new SoapClient($this->wsdlUrl, $options);
            $result = $client->VerifyDriver($licenseNumber);

            // Unwrap SOAP response if needed. This RPC/encoded WSDL returns an
            // array (['verified' => bool, 'licenseNumber' => string]), so array
            // access is used; object access would always resolve to false/empty.
            $verified = isset($result['verified']) ? $result['verified'] : false;
            $licenseNumberResult = isset($result['licenseNumber']) ? $result['licenseNumber'] : '';

            // Handle SOAP's complexType wrapping
            if (is_object($verified) && isset($verified->{'$value'})) {
                $verified = $verified->{'$value'} === true || (string) $verified->{'$value'} === 'true';
            } else {
                $verified = $verified === true || (string) $verified === 'true';
            }

            if (is_object($licenseNumberResult) && isset($licenseNumberResult->{'$value'})) {
                $licenseNumberResult = (string) $licenseNumberResult->{'$value'};
            }

            return [
                'verified' => (bool) $verified,
                'licenseNumber' => $licenseNumberResult,
            ];
        } catch (SoapFault $e) {
            throw new RuntimeException('SOAP verification failed: ' . $e->getMessage(), 502);
        } catch (\Throwable $e) {
            throw new RuntimeException('SOAP verification error: ' . $e->getMessage(), 502);
        }
    }
}