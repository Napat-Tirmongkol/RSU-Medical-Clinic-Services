<?php
/**
 * includes/edms_sla_helper.php
 * EDMS SLA — core helper functions
 *
 * Public API:
 *   sla_get_policy(pdo, doc_type, priority_id?): ?array
 *   sla_compute_deadline(pdo, DateTimeImmutable $start, float $hours, bool $businessOnly): DateTimeImmutable
 *   sla_attach_to_routing(pdo, routing_id, ?policyOverride): void
 *   sla_acknowledge(pdo, routing_id, user_id): bool
 *   sla_extend(pdo, routing_id, ?new_ack, ?new_resolve, reason, user_id): bool
 *   sla_pause(pdo, routing_id, user_id, reason): bool
 *   sla_resume(pdo, routing_id, user_id): bool
 *   sla_mark_warned(pdo, routing_id): bool
 *   sla_mark_breached(pdo, routing_id): bool
 *   sla_mark_met(pdo, routing_id, user_id): bool
 *   sla_remaining_seconds(pdo, routing_id): ?int    // null = no policy attached
 *   sla_state_label(state): string                  // i18n Thai
 *   sla_event_log(pdo, routing_id, doc_id, event_type, ?actor_user_id, ?reason, ?metadata): void
 *
 * Business hours:
 *   อ้างอิงจาก `sys_clinic_hours` (ปฏิทินคลินิก) ผ่าน get_clinic_hours_for_date()
 *   - type='regular' : เวลาประจำสัปดาห์ (weekday + open/close_time)
 *   - type='holiday' : วันหยุดเฉพาะ (specific_date + is_closed)
 *   - type='special' : วันเปิดพิเศษ override holiday/regular
 *
 *   sla_compute_deadline() คำนวณโดย "เดิน" จาก $start ไปทีละช่วง business time
 *   ที่เหลือ — ถ้า $businessOnly=false ใช้ wall-clock ตรงๆ
 *
 * Time zone: ใช้ Asia/Bangkok เสมอ (มาตรฐานโปรเจกต์)
 *
 * Dependencies:
 *   - PDO (จาก config.php)
 *   - includes/clinic_status_helper.php → get_clinic_hours_for_date()
 *   - tables: sys_doc_sla_policies, sys_clinic_hours, sys_doc_sla_events, sys_doc_routings
 */
declare(strict_types=1);

require_once __DIR__ . '/clinic_status_helper.php';

if (!function_exists('sla_tz')) {
    function sla_tz(): DateTimeZone {
        static $tz = null;
        if ($tz === null) $tz = new DateTimeZone('Asia/Bangkok');
        return $tz;
    }
}

if (!function_exists('sla_get_policy')) {
    /**
     * Lookup policy ตาม (doc_type, priority_id)
     * Fallback chain:
     *   1. exact match (doc_type + priority_id)
     *   2. doc_type + priority_id IS NULL (catch-all สำหรับ doc_type นั้น)
     *   3. null
     *
     * Cache per-request.
     */
    function sla_get_policy(PDO $pdo, string $docType, ?int $priorityId): ?array {
        static $cache = [];
        $key = $docType . '|' . ($priorityId ?? 'null');
        if (array_key_exists($key, $cache)) return $cache[$key];

        try {
            // exact match
            if ($priorityId !== null) {
                $st = $pdo->prepare("SELECT * FROM sys_doc_sla_policies
                    WHERE doc_type = ? AND priority_id = ? AND is_active = 1 LIMIT 1");
                $st->execute([$docType, $priorityId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $cache[$key] = $row;
                }
            }
            // catch-all
            $st = $pdo->prepare("SELECT * FROM sys_doc_sla_policies
                WHERE doc_type = ? AND priority_id IS NULL AND is_active = 1 LIMIT 1");
            $st->execute([$docType]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $cache[$key] = ($row ?: null);
        } catch (PDOException $e) {
            error_log('[sla_get_policy] ' . $e->getMessage());
            return $cache[$key] = null;
        }
    }
}

if (!function_exists('sla_get_day_hours')) {
    /**
     * คืน business hours สำหรับวันที่ระบุ — อ่านจาก sys_clinic_hours (ปฏิทินคลินิก)
     *
     * @return array{closed:bool, start:?string, end:?string}
     *   - closed=true → วันหยุด / ยังไม่ตั้งค่า / is_closed=1
     *   - start/end → 'HH:MM:SS' (ภายในวันนั้น)
     *
     * Cache per-request ต่อ date.
     */
    function sla_get_day_hours(PDO $pdo, string $date): array {
        static $cache = [];
        if (isset($cache[$date])) return $cache[$date];

        try {
            $h = get_clinic_hours_for_date($pdo, $date);
            // get_clinic_hours_for_date returns: closed, open_time (HH:MM), close_time, note, source
            if (!empty($h['closed']) || empty($h['open_time']) || empty($h['close_time'])) {
                return $cache[$date] = ['closed' => true, 'start' => null, 'end' => null];
            }
            // Normalize HH:MM → HH:MM:SS
            $start = strlen((string)$h['open_time']) === 5 ? $h['open_time'] . ':00' : $h['open_time'];
            $end   = strlen((string)$h['close_time']) === 5 ? $h['close_time'] . ':00' : $h['close_time'];
            return $cache[$date] = ['closed' => false, 'start' => $start, 'end' => $end];
        } catch (Throwable $e) {
            error_log('[sla_get_day_hours] ' . $e->getMessage());
            return $cache[$date] = ['closed' => true, 'start' => null, 'end' => null];
        }
    }
}

if (!function_exists('sla_is_business_time')) {
    /**
     * ตรวจว่า $dt อยู่ในเวลาทำการ ตามปฏิทินคลินิก
     */
    function sla_is_business_time(PDO $pdo, DateTimeImmutable $dt): bool {
        $hours = sla_get_day_hours($pdo, $dt->format('Y-m-d'));
        if ($hours['closed']) return false;
        $time = $dt->format('H:i:s');
        return ($time >= $hours['start'] && $time < $hours['end']);
    }
}

if (!function_exists('sla_compute_deadline')) {
    /**
     * คำนวณ deadline DATETIME จาก start + ชั่วโมง
     *
     * ถ้า $businessOnly = false → start + $hours hours (wall-clock)
     * ถ้า $businessOnly = true  → เดิน business time ตามปฏิทินคลินิก (sys_clinic_hours)
     *
     * @param float $hours จำนวนชั่วโมง (รองรับทศนิยม เช่น 2.5)
     */
    function sla_compute_deadline(PDO $pdo, DateTimeImmutable $start, float $hours, bool $businessOnly): DateTimeImmutable {
        if ($hours <= 0) return $start;
        $secondsNeeded = (int)round($hours * 3600);

        if (!$businessOnly) {
            return $start->add(new DateInterval('PT' . $secondsNeeded . 'S'));
        }

        $cursor = $start;

        // Hard cap: 365 วันกัน infinite loop (case calendar เพี้ยน)
        $maxIter = 365 * 24 * 60;
        $iter = 0;

        while ($secondsNeeded > 0 && $iter++ < $maxIter) {
            $date = $cursor->format('Y-m-d');
            $hours = sla_get_day_hours($pdo, $date);

            // ปิด / ไม่มีเวลาทำการ → กระโดดไปต้นวันถัดไป
            if ($hours['closed']) {
                $cursor = $cursor->setTime(0, 0, 0)->add(new DateInterval('P1D'));
                continue;
            }

            $dayStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$date} {$hours['start']}", sla_tz());
            $dayEnd   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$date} {$hours['end']}",   sla_tz());

            // ก่อนเวลาเปิด → fast-forward ไป start
            if ($cursor < $dayStart) {
                $cursor = $dayStart;
            }
            // หลังเวลาปิด → กระโดดไปวันถัดไป
            if ($cursor >= $dayEnd) {
                $cursor = $cursor->setTime(0, 0, 0)->add(new DateInterval('P1D'));
                continue;
            }

            // กิน business time ของวันนี้
            $available = $dayEnd->getTimestamp() - $cursor->getTimestamp();
            if ($available >= $secondsNeeded) {
                $cursor = $cursor->add(new DateInterval('PT' . $secondsNeeded . 'S'));
                $secondsNeeded = 0;
            } else {
                $secondsNeeded -= $available;
                $cursor = $cursor->setTime(0, 0, 0)->add(new DateInterval('P1D'));
            }
        }

        return $cursor;
    }
}

if (!function_exists('sla_event_log')) {
    /**
     * เขียน event ลง sys_doc_sla_events (best-effort, ไม่ throw)
     */
    function sla_event_log(
        PDO $pdo,
        int $routingId,
        int $docId,
        string $eventType,
        ?int $actorUserId,
        ?string $reason = null,
        ?array $metadata = null
    ): void {
        try {
            $st = $pdo->prepare("INSERT INTO sys_doc_sla_events
                (routing_id, doc_id, event_type, actor_user_id, reason, metadata_json)
                VALUES (?, ?, ?, ?, ?, ?)");
            $st->execute([
                $routingId,
                $docId,
                $eventType,
                $actorUserId ?: null,
                $reason,
                $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (PDOException $e) {
            error_log('[sla_event_log] ' . $e->getMessage() . ' (event=' . $eventType . ')');
        }
    }
}

if (!function_exists('sla_attach_to_routing')) {
    /**
     * Attach SLA policy + คำนวณ deadline ให้ routing 1 record
     * เรียกหลัง INSERT routing สำเร็จ (ยังไม่อยู่ใน transaction ก็ได้)
     *
     * @param array|null $override [
     *    'ack_deadline_at' => 'Y-m-d H:i:s',
     *    'resolve_deadline_at' => 'Y-m-d H:i:s',
     *    'reason' => string
     * ] — ถ้าระบุ จะข้าม policy lookup แล้วใช้ค่านี้ตรงๆ
     * @return bool
     */
    function sla_attach_to_routing(PDO $pdo, int $routingId, ?array $override = null): bool {
        try {
            $st = $pdo->prepare("SELECT r.id, r.doc_id, r.created_at, d.doc_type, d.priority_id
                FROM sys_doc_routings r
                JOIN sys_doc_documents d ON d.id = r.doc_id
                WHERE r.id = ? LIMIT 1");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return false;

            $start = new DateTimeImmutable($r['created_at'], sla_tz());

            if ($override && !empty($override['ack_deadline_at']) && !empty($override['resolve_deadline_at'])) {
                $ackDl = new DateTimeImmutable($override['ack_deadline_at'], sla_tz());
                $resDl = new DateTimeImmutable($override['resolve_deadline_at'], sla_tz());
                $policyId = null;
                $reason = $override['reason'] ?? 'manual override';
                $eventType = 'override';
            } else {
                $policy = sla_get_policy($pdo, $r['doc_type'], (int)$r['priority_id'] ?: null);
                if (!$policy) {
                    // ไม่มี policy → set state = 'none' (เก็บ routing เฉยๆ ไม่ track SLA)
                    $pdo->prepare("UPDATE sys_doc_routings SET sla_state='none' WHERE id=?")->execute([$routingId]);
                    return false;
                }
                $ackDl = sla_compute_deadline($pdo, $start, (float)$policy['ack_hours'], (bool)$policy['business_hours_only']);
                $resDl = sla_compute_deadline($pdo, $start, (float)$policy['resolve_hours'], (bool)$policy['business_hours_only']);
                $policyId = (int)$policy['id'];
                $reason = null;
                $eventType = 'started';
            }

            $upd = $pdo->prepare("UPDATE sys_doc_routings
                SET policy_id = ?, ack_deadline_at = ?, resolve_deadline_at = ?,
                    sla_state = 'on_track', sla_reason = ?
                WHERE id = ?");
            $upd->execute([
                $policyId,
                $ackDl->format('Y-m-d H:i:s'),
                $resDl->format('Y-m-d H:i:s'),
                $reason,
                $routingId,
            ]);

            sla_event_log($pdo, $routingId, (int)$r['doc_id'], $eventType, null, $reason, [
                'policy_id' => $policyId,
                'ack_deadline'     => $ackDl->format('c'),
                'resolve_deadline' => $resDl->format('c'),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_attach_to_routing] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_acknowledge')) {
    function sla_acknowledge(PDO $pdo, int $routingId, ?int $userId): bool {
        try {
            $st = $pdo->prepare("SELECT id, doc_id, acknowledged_at FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['acknowledged_at']) return false;

            $pdo->prepare("UPDATE sys_doc_routings SET acknowledged_at = NOW() WHERE id = ?")->execute([$routingId]);
            sla_event_log($pdo, $routingId, (int)$r['doc_id'], 'acknowledged', $userId);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_acknowledge] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_extend')) {
    /**
     * ขยาย/ย่น deadline (manual override) — ต้องใส่ reason
     */
    function sla_extend(
        PDO $pdo,
        int $routingId,
        ?string $newAckIso,
        ?string $newResolveIso,
        string $reason,
        ?int $userId
    ): bool {
        try {
            $st = $pdo->prepare("SELECT id, doc_id, ack_deadline_at, resolve_deadline_at FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return false;

            $oldAck = $r['ack_deadline_at'];
            $oldRes = $r['resolve_deadline_at'];

            $sets = [];
            $params = [];
            if ($newAckIso) {
                $sets[] = "ack_deadline_at = ?";
                $params[] = $newAckIso;
            }
            if ($newResolveIso) {
                $sets[] = "resolve_deadline_at = ?";
                $params[] = $newResolveIso;
            }
            if (empty($sets)) return false;

            $params[] = $routingId;
            $pdo->prepare("UPDATE sys_doc_routings SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

            // เลือก event type ตาม direction (extended ถ้ายืดออก, shortened ถ้าย่น)
            $newResMain = $newResolveIso ?: $oldRes;
            $eventType = ($newResMain && $newResMain > $oldRes) ? 'extended' : 'shortened';

            sla_event_log($pdo, $routingId, (int)$r['doc_id'], $eventType, $userId, $reason, [
                'old_ack' => $oldAck,
                'old_resolve' => $oldRes,
                'new_ack' => $newAckIso,
                'new_resolve' => $newResolveIso,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_extend] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_pause')) {
    function sla_pause(PDO $pdo, int $routingId, ?int $userId, ?string $reason = null): bool {
        try {
            $st = $pdo->prepare("SELECT id, doc_id, sla_state, paused_at FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['paused_at']) return false;
            if (in_array($r['sla_state'], ['met', 'cancelled', 'none'], true)) return false;

            $pdo->prepare("UPDATE sys_doc_routings SET paused_at = NOW(), sla_state = 'paused' WHERE id = ?")->execute([$routingId]);
            sla_event_log($pdo, $routingId, (int)$r['doc_id'], 'paused', $userId, $reason);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_pause] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_resume')) {
    function sla_resume(PDO $pdo, int $routingId, ?int $userId): bool {
        try {
            $st = $pdo->prepare("SELECT id, doc_id, sla_state, paused_at, paused_total_secs,
                ack_deadline_at, resolve_deadline_at FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !$r['paused_at']) return false;

            $pausedAt = new DateTimeImmutable($r['paused_at'], sla_tz());
            $now = new DateTimeImmutable('now', sla_tz());
            $pauseSecs = $now->getTimestamp() - $pausedAt->getTimestamp();
            $totalPausedSecs = (int)$r['paused_total_secs'] + max(0, $pauseSecs);

            // ชดเชย deadline ด้วยเวลาที่ pause ไป
            $newAck = $r['ack_deadline_at']
                ? (new DateTimeImmutable($r['ack_deadline_at'], sla_tz()))->add(new DateInterval('PT' . $pauseSecs . 'S'))->format('Y-m-d H:i:s')
                : null;
            $newRes = $r['resolve_deadline_at']
                ? (new DateTimeImmutable($r['resolve_deadline_at'], sla_tz()))->add(new DateInterval('PT' . $pauseSecs . 'S'))->format('Y-m-d H:i:s')
                : null;

            $pdo->prepare("UPDATE sys_doc_routings
                SET paused_at = NULL,
                    paused_total_secs = ?,
                    sla_state = 'on_track',
                    ack_deadline_at = ?,
                    resolve_deadline_at = ?,
                    warned_at = NULL
                WHERE id = ?")
                ->execute([$totalPausedSecs, $newAck, $newRes, $routingId]);

            sla_event_log($pdo, $routingId, (int)$r['doc_id'], 'resumed', $userId, null, [
                'pause_secs' => $pauseSecs,
                'total_paused_secs' => $totalPausedSecs,
                'new_ack' => $newAck,
                'new_resolve' => $newRes,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_resume] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_mark_warned')) {
    function sla_mark_warned(PDO $pdo, int $routingId): bool {
        try {
            $st = $pdo->prepare("SELECT id, doc_id, sla_state, warned_at FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['warned_at']) return false;
            if ($r['sla_state'] !== 'on_track') return false;

            $pdo->prepare("UPDATE sys_doc_routings SET warned_at = NOW(), sla_state = 'warning' WHERE id = ?")->execute([$routingId]);
            sla_event_log($pdo, $routingId, (int)$r['doc_id'], 'warned', null);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_mark_warned] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_mark_breached')) {
    function sla_mark_breached(PDO $pdo, int $routingId): bool {
        try {
            $st = $pdo->prepare("SELECT id, doc_id, sla_state, breached_at FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['breached_at']) return false;
            if (in_array($r['sla_state'], ['met', 'cancelled', 'none', 'paused'], true)) return false;

            $pdo->prepare("UPDATE sys_doc_routings SET breached_at = NOW(), sla_state = 'breached' WHERE id = ?")->execute([$routingId]);
            sla_event_log($pdo, $routingId, (int)$r['doc_id'], 'breached', null);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_mark_breached] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_mark_met')) {
    function sla_mark_met(PDO $pdo, int $routingId, ?int $userId): bool {
        try {
            $st = $pdo->prepare("SELECT id, doc_id, sla_state, met_at FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['met_at']) return false;
            if (in_array($r['sla_state'], ['cancelled', 'none'], true)) return false;

            // ถ้า breached ไปแล้ว ก็ยังเก็บสถานะ breached แต่ stamp met_at เพื่อรู้ว่าเสร็จเมื่อไร
            $newState = ($r['sla_state'] === 'breached') ? 'breached' : 'met';
            $pdo->prepare("UPDATE sys_doc_routings SET met_at = NOW(), sla_state = ? WHERE id = ?")->execute([$newState, $routingId]);
            sla_event_log($pdo, $routingId, (int)$r['doc_id'], 'met', $userId);
            return true;
        } catch (Throwable $e) {
            error_log('[sla_mark_met] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_remaining_seconds')) {
    /**
     * คืนจำนวนวินาทีที่เหลือก่อน resolve_deadline_at
     * - ติดลบ = breach แล้ว
     * - null = no policy attached, paused, met หรือ cancelled
     */
    function sla_remaining_seconds(PDO $pdo, int $routingId): ?int {
        try {
            $st = $pdo->prepare("SELECT resolve_deadline_at, sla_state FROM sys_doc_routings WHERE id = ?");
            $st->execute([$routingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !$r['resolve_deadline_at']) return null;
            if (in_array($r['sla_state'], ['met', 'cancelled', 'none', 'paused'], true)) return null;

            $dl = new DateTimeImmutable($r['resolve_deadline_at'], sla_tz());
            $now = new DateTimeImmutable('now', sla_tz());
            return $dl->getTimestamp() - $now->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sla_state_label')) {
    function sla_state_label(string $state): string {
        return [
            'on_track'  => 'ตามเวลา',
            'warning'   => 'ใกล้หมดเวลา',
            'breached'  => 'เลย deadline',
            'met'       => 'เสร็จตามเวลา',
            'paused'    => 'หยุดชั่วคราว',
            'cancelled' => 'ยกเลิก',
            'none'      => 'ไม่มี SLA',
        ][$state] ?? $state;
    }
}

if (!function_exists('sla_state_color')) {
    function sla_state_color(string $state): string {
        return [
            'on_track'  => 'emerald',
            'warning'   => 'amber',
            'breached'  => 'rose',
            'met'       => 'sky',
            'paused'    => 'slate',
            'cancelled' => 'slate',
            'none'      => 'slate',
        ][$state] ?? 'slate';
    }
}

if (!function_exists('sla_format_remaining_thai')) {
    /**
     * แปลงวินาทีคงเหลือ → "X ชม. Y นาที" หรือ "เลย deadline X ชม."
     */
    function sla_format_remaining_thai(?int $secs): string {
        if ($secs === null) return '-';
        $abs = abs($secs);
        $h = intdiv($abs, 3600);
        $m = intdiv($abs % 3600, 60);
        $parts = [];
        if ($h > 0) $parts[] = "{$h} ชม.";
        if ($m > 0 || $h === 0) $parts[] = "{$m} นาที";
        $str = implode(' ', $parts);
        return $secs < 0 ? "เลย {$str}" : "เหลือ {$str}";
    }
}
