<?php declare(strict_types=1);

/**
 * SakayTa — HTTP layer for /api/drivers/me* (driver self-service).
 *
 * Every action is performed against the authenticated driver's own profile,
 * resolved from the bearer token (role=driver enforced by CurrentUser). A
 * driver can never address another driver: no driver id is accepted from the
 * request.
 */

namespace Sakayta\Driver;

use Sakayta\Auth\CurrentUser;
use Sakayta\Http\Response;

final class DriverController
{
    private DriverService $service;

    public function __construct(?DriverService $service = null)
    {
        $this->service = $service ?? new DriverService();
    }

    /** GET /api/drivers/me -> 200 own profile | 404 none. */
    public function me(): void
    {
        $claims = CurrentUser::requiredDriver();
        Response::json(200, $this->service->getOwnProfile($claims['uid']));
    }

    /** POST /api/drivers/me -> 201 created | 409 already exists. */
    public function create(): void
    {
        $claims = CurrentUser::requiredDriver();
        $body = $this->jsonBody();

        $profile = $this->service->createProfile(
            $claims['uid'],
            isset($body['name']) ? (string) $body['name'] : null,
            isset($body['plateNumber']) ? (string) $body['plateNumber'] : null
        );
        Response::json(201, $profile);
    }

    /** PUT /api/drivers/me -> 200 updated | 404 none | 403 protected fields. */
    public function update(): void
    {
        $claims = CurrentUser::requiredDriver();
        $profile = $this->service->updateProfile($claims['uid'], $this->jsonBody());
        Response::json(200, $profile);
    }

    /** PUT /api/drivers/me/availability -> 200 {availability, driver}. */
    public function setAvailability(): void
    {
        $claims = CurrentUser::requiredDriver();
        $body = $this->jsonBody();

        if (!array_key_exists('available', $body) || !is_bool($body['available'])) {
            Response::json(400, ['error' => 'Field "available" (boolean) is required.']);
            return;
        }

        $result = $this->service->setAvailability($claims['uid'], $body['available']);
        Response::json(200, $result);
    }

    /** POST /api/drivers/me/submit-license -> 200 pending | 404 none. */
    public function submitLicense(): void
    {
        $claims = CurrentUser::requiredDriver();
        $body = $this->jsonBody();

        $result = $this->service->submitLicense(
            $claims['uid'],
            isset($body['licenseNumber']) ? (string) $body['licenseNumber'] : ''
        );
        Response::json(200, $result);
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}