<?php
/**
 * IT Access — supervisor resolution.
 *
 * One definition of "who approves this person's request", shared by submit.php
 * (which routes a new request) and the UI (which shows the requester where it
 * will go). Keeping it here means the two can never disagree.
 *
 * Include-only: defines functions and emits nothing.
 */

if (!function_exists('itaResolveSupervisor')) {

    /**
     * Who should approve requests submitted by this user?
     *
     * Order:
     *   1. the user's own supervisor_id  — an explicit override, used where a
     *      division has internal tiers (e.g. Benefits branch officers report to
     *      a branch supervisor, not the division head)
     *   2. their division's supervisor_id — the default for everyone else
     *   3. null — nobody is set, so the request skips the supervisor step and
     *      goes straight to the ICT queue rather than stalling
     *
     * A candidate is only accepted if they are ACTIVE and still hold the
     * `supervisor` role. Someone who left or had the role revoked must not
     * silently keep receiving approvals — resolution falls through to the next
     * rule instead, which is what keeps requests moving.
     *
     * @return int|null user id of the approver, or null for "route to ICT"
     */
    function itaResolveSupervisor(mysqli $conn, int $userId): ?int {
        $sql = "
            SELECT u.supervisor_id AS own, d.supervisor_id AS div_default
            FROM users u
            LEFT JOIN divisions d ON d.id = u.division_id
            WHERE u.id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;

        foreach ([$row['own'], $row['div_default']] as $candidate) {
            if ($candidate === null) continue;
            $cid = (int)$candidate;
            // Never route to yourself: a supervisor submitting their own request
            // cannot approve it, so fall through (to the division default, then
            // to their delegate, then to ICT).
            if ($cid === $userId) continue;
            if (itaIsUsableSupervisor($conn, $cid)) return $cid;
        }

        // The assigned supervisor is the requester themselves or is unusable —
        // try the division's delegate before giving up and routing to ICT.
        $delegate = itaDivisionDelegate($conn, $userId);
        if ($delegate !== null && $delegate !== $userId && itaIsUsableSupervisor($conn, $delegate)) {
            return $delegate;
        }

        return null;
    }

    /** Is this user active and still holding the supervisor role? */
    function itaIsUsableSupervisor(mysqli $conn, int $userId): bool {
        $stmt = $conn->prepare(
            "SELECT 1 FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ? AND u.is_active = 1 AND r.name = 'supervisor'
             LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }

    /** The delegate set on this user's division, if any. */
    function itaDivisionDelegate(mysqli $conn, int $userId): ?int {
        $stmt = $conn->prepare(
            "SELECT d.delegate_id FROM users u
             LEFT JOIN divisions d ON d.id = u.division_id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return isset($row['delegate_id']) ? (int)$row['delegate_id'] : null;
    }

    /**
     * May this user action the supervisor step on this request?
     *
     * True for the routed supervisor, and for the delegate of the requester's
     * division (absence cover). Superadmins may also act, as the documented
     * override for a request that would otherwise be stuck.
     */
    function itaCanActionSupervisorStep(mysqli $conn, int $userId, int $requestId): bool {
        $stmt = $conn->prepare(
            "SELECT supervisor_id, submitted_by FROM it_access_requests WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;

        if ((int)$row['supervisor_id'] === $userId) return true;

        // Delegate cover, resolved from the REQUESTER's division.
        $delegate = itaDivisionDelegate($conn, (int)$row['submitted_by']);
        if ($delegate !== null && $delegate === $userId) return true;

        return hasRole('superadmin');
    }

    /**
     * Everyone who can be picked as a supervisor on the request form.
     * Active holders of the `supervisor` role, excluding the requester.
     */
    function itaSupervisorChoices(mysqli $conn, int $excludeUserId = 0): array {
        $stmt = $conn->prepare(
            "SELECT u.id, COALESCE(NULLIF(TRIM(u.full_name), ''), u.Username) AS name
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.name = 'supervisor' AND u.is_active = 1 AND u.id <> ?
             ORDER BY name"
        );
        $stmt->bind_param("i", $excludeUserId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(
            static fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']],
            $rows
        );
    }
}
