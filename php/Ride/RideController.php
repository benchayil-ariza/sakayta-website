<?php declare(strict_types=1);

/**
 * SakayTa — HTTP layer for /api/rides/* (ride foundation).
 */

namespace Sakayta\Ride;

use Sakayta\Auth\CurrentUser;
use Sakayta\Http\Response;

final class RideController
{
    private RideService $service;

    public function __construct(?RideService $service = null)
    {
        $this->service = $service ?? new RideService();
    }

    /** POST /api/rides -> 201 */
    public function create(): void
    {
        $claims = CurrentUser::required();

        if (($claims['role'] ?? '') !== 'commuter') {
            throw new RideException('Only commuters can create rides.', 403);
        }

        $body = $this->jsonBody();

        $required = ['passengerName', 'pickupLat', 'pickupLng', 'dropoffLat', 'dropoffLng'];
        foreach ($required as $field) {
            if (!isset($body[$field]) || $body[$field] === '' || $body[$field] === null) {
                throw new RideException('All required fields are required.', 400);
            }
        }

        $passengerName = trim((string) $body['passengerName']);
        if ($passengerName === '' || strlen($passengerName) > 255) {
            throw new RideException('Passenger name must be 1-255 characters.', 400);
        }
        $rideType = $body['rideType'] ?? 'Tricycle';
        if (!in_array($rideType, ['Tricycle', 'Bicycle', 'Car', 'Motorcycle', 'Habal-Habal'], true)) {
            throw new RideException('Invalid ride type.', 400);
        }

        if (!is_numeric($body['pickupLat']) || !is_numeric($body['pickupLng']) ||
            !is_numeric($body['dropoffLat']) || !is_numeric($body['dropoffLng'])) {
            throw new RideException('Coordinates must be numbers.', 400);
        }

        $pickupLat = (float) $body['pickupLat'];
        $pickupLng = (float) $body['pickupLng'];
        $dropoffLat = (float) $body['dropoffLat'];
        $dropoffLng = (float) $body['dropoffLng'];

        if ($pickupLat < -90 || $pickupLat > 90 || $dropoffLat < -90 || $dropoffLat > 90) {
            throw new RideException('Latitude must be between -90 and 90.', 400);
        }
        if ($pickupLng < -180 || $pickupLng > 180 || $dropoffLng < -180 || $dropoffLng > 180) {
            throw new RideException('Longitude must be between -180 and 180.', 400);
        }

        $ride = $this->service->createRide(
            $claims['uid'],
            $passengerName,
            $rideType,
            $pickupLat,
            $pickupLng,
            $dropoffLat,
            $dropoffLng
        );
        Response::json(201, $ride);
    }

    /** GET /api/rides -> 200 list of own rides */
    public function list(): void
    {
        $claims = CurrentUser::required();
        Response::json(200, $this->service->getMyRides($claims['uid']));
    }

    /** GET /api/rides/:id -> 200 | 404 */
    public function getById(string $id): void
    {
        $claims = CurrentUser::required();
        if (trim($id) === '') {
            throw new RideException('Ride ID is required.', 400);
        }
        $ride = $this->service->getRideById($claims['uid'], $id);
        Response::json(200, $ride);
    }

    /** GET /api/rides/estimate -> 200 */
    public function estimate(): void
    {
        $input = !empty($_GET) ? $_GET : $this->jsonBody();

        $required = ['pickupLat', 'pickupLng', 'dropoffLat', 'dropoffLng'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                throw new RideException('All required fields are required.', 400);
            }
        }

        if (!is_numeric($input['pickupLat']) || !is_numeric($input['pickupLng']) ||
            !is_numeric($input['dropoffLat']) || !is_numeric($input['dropoffLng'])) {
            throw new RideException('Coordinates must be numbers.', 400);
        }

        $pickupLat = (float) $input['pickupLat'];
        $pickupLng = (float) $input['pickupLng'];
        $dropoffLat = (float) $input['dropoffLat'];
        $dropoffLng = (float) $input['dropoffLng'];

        $estimate = $this->service->estimateRide($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        Response::json(200, $estimate);
    }

    /** POST /api/rides/:id/accept -> 200 | 401 | 403 | 404 | 409 */
    public function accept(string $id): void
    {
        $claims = CurrentUser::required();
        if (($claims['role'] ?? '') !== 'driver') {
            throw new RideException('Only drivers can accept rides.', 403);
        }
        $driver = $this->service->getDriverProfile($claims['uid']);
        $ride = $this->service->getRideForDriver($id, $claims['uid']);
        $result = $this->service->acceptRide($claims['uid'], $id, $driver['id'] ?? $driver['user_id']);
        Response::json(200, $result);
    }

    /** POST /api/rides/:id/decline -> 200 | 401 | 403 | 404 */
    public function decline(string $id): void
    {
        $claims = CurrentUser::required();
        if (($claims['role'] ?? '') !== 'driver') {
            throw new RideException('Only drivers can decline rides.', 403);
        }
        $result = $this->service->declineRide($claims['uid'], $id);
        Response::json(200, $result);
    }

    /** POST /api/rides/:id/cancel -> 200 | 409 | 403 | 404 */
    public function cancel(string $id): void
    {
        $claims = CurrentUser::required();

        if (($claims['role'] ?? '') !== 'commuter') {
            throw new RideException('Only commuters can cancel rides.', 403);
        }

        if (trim($id) === '') {
            throw new RideException('Ride ID is required.', 400);
        }

        $result = $this->service->cancelRide($claims['uid'], $id);
        Response::json(200, $result);
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}