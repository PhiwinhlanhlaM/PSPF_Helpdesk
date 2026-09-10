<?php
/**
 * Time-window vehicle availability.
 *
 * The booking model used to treat vehicles.status as a single global mutex:
 * a car flipped to 'allocated' the moment it was assigned to ANY request and
 * only returned to 'available' when that trip was closed. That both allowed
 * double-booking (the web dashboard never checked the flag) and blocked a car
 * from being booked for two non-overlapping future trips.
 *
 * This helper makes the requested date/time window the source of truth: a
 * vehicle is available for a request only when no other live allocation
 * overlaps that window. The vehicles.status flag is kept for display and for
 * marking cars out of service (maintenance/retired), but overlap is what
 * decides bookability.
 *
 * A request's window is [date_required + time_required, expected_return_date
 * end-of-day]. For an existing allocation that has not been closed, the
 * occupied end is stretched to at least NOW(), so a trip that is overdue for
 * return keeps the car blocked for the present instead of appearing free the
 * day its expected_return_date passes.
 */

// Request statuses that hold a vehicle for its window. pending_driver has no
// vehicle assigned yet; rejected/closed have released it.
if (!defined('VB_ACTIVE_ALLOCATION_STATUSES')) {
    define('VB_ACTIVE_ALLOCATION_STATUSES', ['pending_supervisor', 'pending_hrm', 'approved']);
}

// Vehicle operational statuses that take a car out of the bookable fleet
// entirely, regardless of the requested window. 'available' and 'allocated'
// both stay bookable subject to overlap.
if (!defined('VB_OUT_OF_SERVICE_STATUSES')) {
    define('VB_OUT_OF_SERVICE_STATUSES', ['maintenance', 'retired', 'unavailable', 'out_of_service']);
}

/**
 * Build a safe, comma-separated, quoted SQL list from an array of code-defined
 * constants. The values are never user input, but quoting via PDO keeps this
 * correct regardless.
 */
function vbQuoteList(PDO $conn, array $values): string
{
    return implode(',', array_map([$conn, 'quote'], $values));
}

/**
 * SQL expression for the start of an allocation's occupied window.
 * $alias is the vehicle_requests table alias.
 */
function vbOccupiedStartSql(string $alias = 'vr'): string
{
    return "CONCAT({$alias}.date_required, ' ', COALESCE({$alias}.time_required, '00:00:00'))";
}

/**
 * SQL expression for the end of an allocation's occupied window. Stretched to
 * NOW() for still-open trips so an overdue vehicle stays blocked for the
 * present rather than looking free once its return date passes.
 */
function vbOccupiedEndSql(string $alias = 'vr'): string
{
    return "GREATEST("
        . "CONCAT(COALESCE({$alias}.expected_return_date, {$alias}.date_required), ' 23:59:59'), "
        . "NOW())";
}

/**
 * Resolve the requested [start, end] datetime window for a request.
 * Returns ['start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s'] or null if the
 * request has no usable date_required.
 */
function getRequestWindow(PDO $conn, int $request_id): ?array
{
    $stmt = $conn->prepare("
        SELECT
            CONCAT(date_required, ' ', COALESCE(time_required, '00:00:00'))   AS win_start,
            CONCAT(COALESCE(expected_return_date, date_required), ' 23:59:59') AS win_end
        FROM vehicle_requests
        WHERE request_id = ?
    ");
    $stmt->execute([$request_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['win_start'])) {
        return null;
    }
    return ['start' => $row['win_start'], 'end' => $row['win_end']];
}

/**
 * Vehicles that are free for a given window.
 *
 * A vehicle is returned when it is in service AND has no active allocation
 * whose occupied window overlaps [$winStart, $winEnd]. $excludeRequestId lets
 * a request ignore its own current allocation (so re-listing options for an
 * in-flight request does not treat the request as competing with itself).
 *
 * Pass $lockRows = true inside a transaction to lock the conflicting
 * allocation rows for the duration, closing the race between two concurrent
 * assignments of the same vehicle.
 */
function availableVehiclesForWindow(
    PDO $conn,
    string $winStart,
    string $winEnd,
    ?int $excludeRequestId = null,
    bool $lockRows = false
): array {
    $active = vbQuoteList($conn, VB_ACTIVE_ALLOCATION_STATUSES);
    $oos    = vbQuoteList($conn, VB_OUT_OF_SERVICE_STATUSES);
    $occStart = vbOccupiedStartSql('vr');
    $occEnd   = vbOccupiedEndSql('vr');

    $excludeClause = $excludeRequestId !== null ? "AND vr.request_id <> :exclude_id" : "";

    $sql = "
        SELECT v.vehicle_id, v.registration, v.make, v.model, v.status
        FROM vehicles v
        WHERE LOWER(v.status) NOT IN ($oos)
          AND NOT EXISTS (
              SELECT 1
              FROM vehicle_requests vr
              WHERE vr.vehicle_id = v.vehicle_id
                AND vr.status IN ($active)
                $excludeClause
                AND $occStart <= :win_end
                AND $occEnd   >= :win_start
          )
        ORDER BY v.registration
        " . ($lockRows ? "FOR UPDATE" : "");

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':win_end', $winEnd);
    $stmt->bindValue(':win_start', $winStart);
    if ($excludeRequestId !== null) {
        $stmt->bindValue(':exclude_id', $excludeRequestId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Vehicles that are free for a specific request's window. Returns [] if the
 * request window cannot be resolved.
 */
function availableVehiclesForRequest(PDO $conn, int $request_id, bool $lockRows = false): array
{
    $win = getRequestWindow($conn, $request_id);
    if (!$win) {
        return [];
    }
    return availableVehiclesForWindow($conn, $win['start'], $win['end'], $request_id, $lockRows);
}

/**
 * True when $vehicle_id can be assigned to $request_id without clashing with
 * another live allocation over the request's window. Also false when the
 * vehicle is out of service or does not exist.
 *
 * Call inside a transaction with $lockRow = true to make the check-then-assign
 * race safe: it locks any overlapping allocation rows so a concurrent
 * assignment cannot slip a clashing booking in between the check and the write.
 */
function isVehicleAvailableForRequest(PDO $conn, int $vehicle_id, int $request_id, bool $lockRow = false): bool
{
    $win = getRequestWindow($conn, $request_id);
    if (!$win) {
        return false;
    }

    // Out of service / unknown vehicle?
    $vs = $conn->prepare("SELECT LOWER(status) FROM vehicles WHERE vehicle_id = ?");
    $vs->execute([$vehicle_id]);
    $status = $vs->fetchColumn();
    if ($status === false) {
        return false;
    }
    if (in_array($status, array_map('strtolower', VB_OUT_OF_SERVICE_STATUSES), true)) {
        return false;
    }

    $active   = vbQuoteList($conn, VB_ACTIVE_ALLOCATION_STATUSES);
    $occStart = vbOccupiedStartSql('vr');
    $occEnd   = vbOccupiedEndSql('vr');

    $sql = "
        SELECT 1
        FROM vehicle_requests vr
        WHERE vr.vehicle_id = :vehicle_id
          AND vr.request_id <> :request_id
          AND vr.status IN ($active)
          AND $occStart <= :win_end
          AND $occEnd   >= :win_start
        LIMIT 1
        " . ($lockRow ? "FOR UPDATE" : "");

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':vehicle_id', $vehicle_id, PDO::PARAM_INT);
    $stmt->bindValue(':request_id', $request_id, PDO::PARAM_INT);
    $stmt->bindValue(':win_end', $win['end']);
    $stmt->bindValue(':win_start', $win['start']);
    $stmt->execute();

    // Available when no overlapping allocation was found.
    return $stmt->fetchColumn() === false;
}
