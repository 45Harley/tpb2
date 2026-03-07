<?php
/**
 * FeedEngine — Phase 4 of the Civic Engine.
 *
 * Syncs external data sources (threats, bills, executive orders, declarations)
 * into the ballot/poll system, creating auto-generated polls for citizen voting.
 *
 * All methods are static.
 *
 * Depends on: Ballot class, tables: polls, executive_threats, elected_officials,
 *             declarations (optional), bills (optional), executive_orders (optional)
 */

require_once __DIR__ . '/ballot.php';

class FeedEngine
{
    /* ------------------------------------------------------------------ */
    /*  1. syncThreats                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Find threats >= minSeverity without corresponding polls and create yes_no ballots.
     *
     * Checks both the legacy threat_id column AND the new source_type/source_id columns
     * to avoid duplicates with polls created by the old sync in poll/admin.php.
     *
     * @param PDO $pdo
     * @param int $minSeverity  Minimum severity_score (default 300)
     * @return array ['created'=>N, 'skipped'=>N, 'errors'=>array]
     */
    public static function syncThreats(PDO $pdo, int $minSeverity = 300): array
    {
        $created = 0;
        $skipped = 0;
        $errors  = [];

        // Find threats that have no corresponding poll (check both legacy and new linking)
        $sql = "SELECT et.threat_id, et.title, et.severity_score, et.branch,
                       et.official_id, eo.full_name AS representative
                FROM executive_threats et
                LEFT JOIN elected_officials eo ON eo.id = et.official_id
                LEFT JOIN polls p1 ON p1.source_type = 'threat' AND p1.source_id = et.threat_id
                LEFT JOIN polls p2 ON p2.threat_id = et.threat_id AND p2.poll_type = 'threat'
                WHERE et.severity_score >= :severity
                  AND p1.poll_id IS NULL
                  AND p2.poll_id IS NULL
                ORDER BY et.severity_score DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':severity' => $minSeverity]);
        $threats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($threats as $threat) {
            $rep = $threat['representative'] ?? 'the responsible official';
            $question = "Should $rep be held accountable for: " . $threat['title'] . "?";

            // Threats are federal scope by default (executive/congressional/judicial are all federal)
            $scopeType = 'federal';
            $scopeId   = null;

            $result = Ballot::create($pdo, [
                'question'    => $question,
                'vote_type'   => 'yes_no',
                'scope_type'  => $scopeType,
                'scope_id'    => $scopeId,
                'source_type' => 'threat',
                'source_id'   => (int) $threat['threat_id'],
            ]);

            if (isset($result['error'])) {
                $errors[] = "Threat #{$threat['threat_id']}: {$result['error']}";
                $skipped++;
            } else {
                $created++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /* ------------------------------------------------------------------ */
    /*  2. syncBills                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Sync bills into polls. Stub — checks if bills table exists first.
     *
     * @return array ['created'=>N, 'skipped'=>N, 'error'=>string|null]
     */
    public static function syncBills(PDO $pdo): array
    {
        // Check if bills table exists
        $r = $pdo->query("SHOW TABLES LIKE 'bills'");
        if ($r->rowCount() === 0) {
            return ['created' => 0, 'skipped' => 0, 'error' => 'bills table not found'];
        }

        $created = 0;
        $skipped = 0;
        $errors  = [];

        // Find bills without corresponding polls
        $sql = "SELECT b.bill_id, b.title, b.scope_type, b.scope_id
                FROM bills b
                LEFT JOIN polls p ON p.source_type = 'bill' AND p.source_id = b.bill_id
                WHERE p.poll_id IS NULL
                ORDER BY b.created_at DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($bills as $bill) {
                $question = "Do you support this bill: " . $bill['title'] . "?";

                $result = Ballot::create($pdo, [
                    'question'    => $question,
                    'vote_type'   => 'yes_no',
                    'scope_type'  => $bill['scope_type'] ?? 'federal',
                    'scope_id'    => $bill['scope_id'] ?? null,
                    'source_type' => 'bill',
                    'source_id'   => (int) $bill['bill_id'],
                ]);

                if (isset($result['error'])) {
                    $errors[] = "Bill #{$bill['bill_id']}: {$result['error']}";
                    $skipped++;
                } else {
                    $created++;
                }
            }
        } catch (PDOException $e) {
            return ['created' => $created, 'skipped' => $skipped, 'error' => $e->getMessage()];
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /* ------------------------------------------------------------------ */
    /*  3. syncExecutiveOrders                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Sync executive orders into polls. Stub — checks if table exists first.
     *
     * @return array ['created'=>N, 'skipped'=>N, 'error'=>string|null]
     */
    public static function syncExecutiveOrders(PDO $pdo): array
    {
        // Check if executive_orders table exists
        $r = $pdo->query("SHOW TABLES LIKE 'executive_orders'");
        if ($r->rowCount() === 0) {
            return ['created' => 0, 'skipped' => 0, 'error' => 'executive_orders table not found'];
        }

        $created = 0;
        $skipped = 0;
        $errors  = [];

        $sql = "SELECT eo.order_id, eo.title, eo.scope_type, eo.scope_id
                FROM executive_orders eo
                LEFT JOIN polls p ON p.source_type = 'executive_order' AND p.source_id = eo.order_id
                WHERE p.poll_id IS NULL
                ORDER BY eo.created_at DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($orders as $order) {
                $question = "Do you support this executive order: " . $order['title'] . "?";

                $result = Ballot::create($pdo, [
                    'question'    => $question,
                    'vote_type'   => 'yes_no',
                    'scope_type'  => $order['scope_type'] ?? 'federal',
                    'scope_id'    => $order['scope_id'] ?? null,
                    'source_type' => 'executive_order',
                    'source_id'   => (int) $order['order_id'],
                ]);

                if (isset($result['error'])) {
                    $errors[] = "EO #{$order['order_id']}: {$result['error']}";
                    $skipped++;
                } else {
                    $created++;
                }
            }
        } catch (PDOException $e) {
            return ['created' => $created, 'skipped' => $skipped, 'error' => $e->getMessage()];
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /* ------------------------------------------------------------------ */
    /*  4. syncDeclarations                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Find ratified declarations without escalation polls and create them.
     *
     * Town declarations escalate to state scope, state to federal.
     * Links via the declaration_id column on polls.
     *
     * @return array ['created'=>N, 'skipped'=>N, 'error'=>string|null]
     */
    public static function syncDeclarations(PDO $pdo): array
    {
        // Check if declarations table exists
        $r = $pdo->query("SHOW TABLES LIKE 'declarations'");
        if ($r->rowCount() === 0) {
            return ['created' => 0, 'skipped' => 0, 'error' => 'declarations table not found'];
        }

        $created = 0;
        $skipped = 0;
        $errors  = [];

        // Find ratified declarations without escalation polls
        // Town -> state, state -> federal. Federal declarations don't escalate.
        $sql = "SELECT d.declaration_id, d.title, d.scope_type, d.scope_id,
                       d.group_id, ig.name AS group_name
                FROM declarations d
                LEFT JOIN idea_groups ig ON ig.id = d.group_id
                LEFT JOIN polls p ON p.declaration_id = d.declaration_id
                                 AND p.source_type = 'manual'
                WHERE d.status = 'ratified'
                  AND d.scope_type IN ('town', 'state')
                  AND p.poll_id IS NULL
                ORDER BY d.ratified_at DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $declarations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($declarations as $decl) {
                $groupName   = $decl['group_name'] ?? 'A group';
                $parentLevel = $decl['scope_type'] === 'town' ? 'state' : 'federal';

                $question = "$groupName declared: {$decl['title']} -- support escalating to $parentLevel level?";

                // Escalation poll targets the parent scope
                $escalationScopeType = $parentLevel;
                $escalationScopeId   = null;

                // For town->state, derive state from scope_id (format: "ct-putnam" -> state "ct")
                if ($decl['scope_type'] === 'town' && !empty($decl['scope_id'])) {
                    $parts = explode('-', $decl['scope_id'], 2);
                    $escalationScopeId = strtoupper($parts[0] ?? '');
                }

                $ballotData = [
                    'question'    => $question,
                    'vote_type'   => 'yes_no',
                    'scope_type'  => $escalationScopeType,
                    'scope_id'    => $escalationScopeId ?: null,
                    'source_type' => 'manual',
                    'source_id'   => (int) $decl['declaration_id'],
                ];

                $result = Ballot::create($pdo, $ballotData);

                if (isset($result['error'])) {
                    $errors[] = "Declaration #{$decl['declaration_id']}: {$result['error']}";
                    $skipped++;
                } else {
                    // Link declaration_id on the new poll
                    $pdo->prepare("UPDATE polls SET declaration_id = :did WHERE poll_id = :pid")
                        ->execute([':did' => (int) $decl['declaration_id'], ':pid' => $result['poll_id']]);
                    $created++;
                }
            }
        } catch (PDOException $e) {
            return ['created' => $created, 'skipped' => $skipped, 'error' => $e->getMessage()];
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /* ------------------------------------------------------------------ */
    /*  5. syncAll                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Run all syncs and return combined results.
     *
     * @return array Keyed by source type, each with created/skipped/error(s)
     */
    public static function syncAll(PDO $pdo): array
    {
        return [
            'threats'          => self::syncThreats($pdo),
            'bills'            => self::syncBills($pdo),
            'executive_orders' => self::syncExecutiveOrders($pdo),
            'declarations'     => self::syncDeclarations($pdo),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  6. getFeedItems                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Get auto-generated polls (source_type != 'manual') for the feed.
     *
     * @param PDO         $pdo
     * @param string|null $scopeType  Filter by scope (federal, state, town)
     * @param string|null $scopeId    Filter by scope_id
     * @param int         $limit
     * @param int         $offset
     * @return array  Array of poll rows with vote counts
     */
    public static function getFeedItems(
        PDO $pdo,
        ?string $scopeType = null,
        ?string $scopeId = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $sql = "SELECT p.*,
                       COUNT(DISTINCT pv.user_id) AS total_votes,
                       SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) AS yea_votes,
                       SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) AS nay_votes
                FROM polls p
                LEFT JOIN poll_votes pv ON pv.poll_id = p.poll_id
                WHERE p.source_type IS NOT NULL
                  AND p.source_type != 'manual'";

        $params = [];

        if ($scopeType !== null) {
            $sql .= " AND p.scope_type = :scope_type";
            $params[':scope_type'] = $scopeType;
        }

        if ($scopeId !== null) {
            $sql .= " AND p.scope_id = :scope_id";
            $params[':scope_id'] = $scopeId;
        }

        $sql .= " GROUP BY p.poll_id ORDER BY p.created_at DESC LIMIT :lim OFFSET :off";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ------------------------------------------------------------------ */
    /*  7. getStats                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Count polls grouped by source_type.
     *
     * @return array ['threat'=>N, 'bill'=>N, 'executive_order'=>N, 'group'=>N, 'manual'=>N, 'total'=>N]
     */
    public static function getStats(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT COALESCE(source_type, 'manual') AS src, COUNT(*) AS cnt
             FROM polls
             GROUP BY source_type"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stats = [
            'threat'          => (int) ($rows['threat'] ?? 0),
            'bill'            => (int) ($rows['bill'] ?? 0),
            'executive_order' => (int) ($rows['executive_order'] ?? 0),
            'group'           => (int) ($rows['group'] ?? 0),
            'manual'          => (int) ($rows['manual'] ?? 0),
        ];

        // Also count legacy threat polls (poll_type='threat' without source_type)
        $legacyStmt = $pdo->query(
            "SELECT COUNT(*) FROM polls WHERE poll_type = 'threat' AND (source_type IS NULL OR source_type = 'manual')"
        );
        $legacyCount = (int) $legacyStmt->fetchColumn();
        $stats['threat'] += $legacyCount;

        $stats['total'] = array_sum($stats);

        return $stats;
    }
}
