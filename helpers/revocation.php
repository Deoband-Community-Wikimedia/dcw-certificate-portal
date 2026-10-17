<?php
/**
 * Certificate revocation helpers (issue #159).
 *
 * Revocation state lives on event_participants (status, revocation_reason,
 * revoked_at, revoked_by). Every revoke/reinstate is tenant-checked via
 * verify_event_access() and written to audit_logs.
 */

if (!defined('CERT_STATUS_ACTIVE')) {
    define('CERT_STATUS_ACTIVE', 'active');
    define('CERT_STATUS_REVOKED', 'revoked');
}

if (!function_exists('revocation_ensure_schema')) {
    /**
     * Idempotently adds the revocation columns to event_participants.
     * Safe to run on every deploy; works on MySQL and SQLite (tests).
     */
    function revocation_ensure_schema(PDO $pdo): void {
        $columns = [
            'status'            => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'revocation_reason' => 'VARCHAR(255) NULL',
            'revoked_at'        => 'DATETIME NULL',
            'revoked_by'        => 'VARCHAR(50) NULL',
        ];
        foreach ($columns as $name => $definition) {
            try {
                $pdo->query("SELECT $name FROM event_participants LIMIT 0")->closeCursor();
            } catch (PDOException $e) {
                $pdo->exec("ALTER TABLE event_participants ADD COLUMN $name $definition");
            }
        }
    }
}

if (!function_exists('certificate_is_revoked')) {
    function certificate_is_revoked(PDO $pdo, string $certId): bool {
        $stmt = $pdo->prepare("SELECT status FROM event_participants WHERE certificate_id = ?");
        $stmt->execute([$certId]);
        return $stmt->fetchColumn() === CERT_STATUS_REVOKED;
    }
}

if (!function_exists('revoke_certificate')) {
    /**
     * Revokes a participant's certificate for one event.
     *
     * @return array{success: bool, error?: string, certificate_id?: string}
     *   error: reason_required | reason_too_long | unauthorized | not_found | not_active
     */
    function revoke_certificate(PDO $pdo, int $eventId, int $participantId, string $reason): array {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'error' => 'reason_required'];
        }
        if (mb_strlen($reason) > 255) {
            return ['success' => false, 'error' => 'reason_too_long'];
        }
        // Tenant guard: org admins can only touch their own organization's events.
        if (verify_event_access($pdo, $eventId) === null) {
            return ['success' => false, 'error' => 'unauthorized'];
        }

        $stmt = $pdo->prepare("SELECT certificate_id FROM event_participants WHERE participant_id = ? AND event_id = ?");
        $stmt->execute([$participantId, $eventId]);
        $certId = $stmt->fetchColumn();
        if (!$certId) {
            return ['success' => false, 'error' => 'not_found'];
        }

        $stmt = $pdo->prepare("
            UPDATE event_participants
            SET status = ?, revocation_reason = ?, revoked_at = ?, revoked_by = ?
            WHERE participant_id = ? AND event_id = ? AND status = ?
        ");
        $stmt->execute([
            CERT_STATUS_REVOKED,
            $reason,
            date('Y-m-d H:i:s'),
            $_SESSION['admin_username'] ?? null,
            $participantId,
            $eventId,
            CERT_STATUS_ACTIVE,
        ]);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'not_active'];
        }

        log_audit_action($pdo, 'Certificate Revoked', "Cert: {$certId} | Event ID: {$eventId} | Reason: {$reason}");
        return ['success' => true, 'certificate_id' => $certId];
    }
}

if (!function_exists('reinstate_certificate')) {
    /**
     * Reverses a revocation. The previous reason is preserved in the audit log
     * because the row's revocation fields are cleared.
     *
     * @return array{success: bool, error?: string, certificate_id?: string}
     *   error: unauthorized | not_revoked
     */
    function reinstate_certificate(PDO $pdo, int $eventId, int $participantId): array {
        if (verify_event_access($pdo, $eventId) === null) {
            return ['success' => false, 'error' => 'unauthorized'];
        }

        $stmt = $pdo->prepare("
            SELECT certificate_id, revocation_reason FROM event_participants
            WHERE participant_id = ? AND event_id = ? AND status = ?
        ");
        $stmt->execute([$participantId, $eventId, CERT_STATUS_REVOKED]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'error' => 'not_revoked'];
        }

        $stmt = $pdo->prepare("
            UPDATE event_participants
            SET status = ?, revocation_reason = NULL, revoked_at = NULL, revoked_by = NULL
            WHERE participant_id = ? AND event_id = ? AND status = ?
        ");
        $stmt->execute([CERT_STATUS_ACTIVE, $participantId, $eventId, CERT_STATUS_REVOKED]);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'not_revoked'];
        }

        log_audit_action($pdo, 'Certificate Reinstated', "Cert: {$row['certificate_id']} | Event ID: {$eventId} | Was revoked for: {$row['revocation_reason']}");
        return ['success' => true, 'certificate_id' => $row['certificate_id']];
    }
}