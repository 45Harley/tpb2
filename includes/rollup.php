<?php
/**
 * Rollup — Phase 5 of the Civic Engine: The Fractal.
 *
 * Aggregates civic data up the jurisdiction hierarchy:
 *   Town -> State -> Federal
 *
 * Provides convergence detection (similar declarations across jurisdictions)
 * and "Beam to Desk" representative targeting.
 *
 * All methods are static.
 *
 * Depends on tables: idea_groups, idea_group_members, declarations,
 *                    public_opinions, polls, poll_votes, states, towns,
 *                    elected_officials (optional)
 */

require_once __DIR__ . '/opinion.php';

class Rollup
{
    /* ------------------------------------------------------------------ */
    /*  Helper: check if a table exists                                    */
    /* ------------------------------------------------------------------ */

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return $stmt->rowCount() > 0;
    }

    /* ------------------------------------------------------------------ */
    /*  Helper: pulse score calculation                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Calculate a composite civic pulse score (0-100).
     *
     * Weights:
     *   Groups activity:   30%
     *   Declarations:      30%
     *   Opinions/votes:    20%
     *   Ballots:           20%
     *
     * Each sub-score is capped at 100.
     */
    private static function calculatePulse(
        int $activeGroups,
        int $totalGroups,
        int $ratifiedDecl,
        int $totalDecl,
        int $totalOpinions,
        int $activeBallots,
        int $totalBallots
    ): float {
        // Groups: ratio of active to total, scaled. Min 1 active = 25 pts.
        $pgGroups = 0;
        if ($totalGroups > 0) {
            $pgGroups = min(100, ($activeGroups / max($totalGroups, 1)) * 100);
        }
        if ($activeGroups > 0 && $pgGroups < 25) {
            $pgGroups = 25;
        }

        // Declarations: bonus for ratified
        $pgDecl = 0;
        if ($totalDecl > 0) {
            $pgDecl = min(100, ($ratifiedDecl / max($totalDecl, 1)) * 100);
        }
        if ($ratifiedDecl > 0 && $pgDecl < 25) {
            $pgDecl = 25;
        }

        // Opinions: log scale, 10 = 50pts, 50 = 80pts, 100 = 100pts
        $pgOpinions = 0;
        if ($totalOpinions > 0) {
            $pgOpinions = min(100, log10($totalOpinions + 1) * 50);
        }

        // Ballots: active ballots count
        $pgBallots = 0;
        if ($totalBallots > 0) {
            $pgBallots = min(100, ($activeBallots / max($totalBallots, 1)) * 100);
        }
        if ($activeBallots > 0 && $pgBallots < 25) {
            $pgBallots = 25;
        }

        return round(($pgGroups * 0.3) + ($pgDecl * 0.3) + ($pgOpinions * 0.2) + ($pgBallots * 0.2), 1);
    }

    /* ------------------------------------------------------------------ */
    /*  1. getTownRollup                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Aggregate all group activity, declarations, opinions, and ballots for a town.
     *
     * @param PDO $pdo
     * @param int $townId
     * @return array
     */
    public static function getTownRollup(PDO $pdo, int $townId): array
    {
        // Get town info for scope_id
        $stmt = $pdo->prepare(
            "SELECT t.id, t.name, t.slug, s.abbr AS state_abbr
             FROM towns t
             JOIN states s ON s.id = t.state_id
             WHERE t.id = :tid LIMIT 1"
        );
        $stmt->execute([':tid' => $townId]);
        $town = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$town) {
            return ['error' => 'Town not found'];
        }

        $scopeId = strtolower($town['state_abbr']) . '-' . $town['slug'];

        // Groups
        $stmtG = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status IN ('active','crystallizing') THEN 1 ELSE 0 END) AS active
            FROM idea_groups
            WHERE scope = 'town' AND town_id = :tid
              AND status IN ('forming','active','crystallizing','crystallized')
        ");
        $stmtG->execute([':tid' => $townId]);
        $groups = $stmtG->fetch(PDO::FETCH_ASSOC);
        $groupsTotal  = (int) ($groups['total'] ?? 0);
        $groupsActive = (int) ($groups['active'] ?? 0);

        // Groups with declarations
        $stmtGD = $pdo->prepare("
            SELECT COUNT(DISTINCT d.group_id) AS cnt
            FROM declarations d
            JOIN idea_groups g ON g.id = d.group_id
            WHERE g.scope = 'town' AND g.town_id = :tid
        ");
        $stmtGD->execute([':tid' => $townId]);
        $groupsWithDecl = (int) $stmtGD->fetchColumn();

        // Declarations
        $stmtD = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'ratified' THEN 1 ELSE 0 END) AS ratified,
                   SUM(CASE WHEN status = 'voting' THEN 1 ELSE 0 END) AS voting
            FROM declarations
            WHERE scope_type = 'town' AND scope_id = :sid
        ");
        $stmtD->execute([':sid' => $scopeId]);
        $decl = $stmtD->fetch(PDO::FETCH_ASSOC);
        $declTotal    = (int) ($decl['total'] ?? 0);
        $declRatified = (int) ($decl['ratified'] ?? 0);
        $declVoting   = (int) ($decl['voting'] ?? 0);

        // Opinions
        $stmtO = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM public_opinions
            WHERE scope_type = 'town' AND scope_id = :sid
        ");
        $stmtO->execute([':sid' => $scopeId]);
        $opinionsTotal = (int) $stmtO->fetchColumn();

        // Ballots
        $stmtB = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN active = 1 AND closed_at IS NULL THEN 1 ELSE 0 END) AS active_count
            FROM polls
            WHERE scope_type = 'town' AND scope_id = :sid
        ");
        $stmtB->execute([':sid' => $scopeId]);
        $ballots = $stmtB->fetch(PDO::FETCH_ASSOC);
        $ballotsTotal  = (int) ($ballots['total'] ?? 0);
        $ballotsActive = (int) ($ballots['active_count'] ?? 0);

        // Pulse
        $pulseScore = self::calculatePulse(
            $groupsActive, $groupsTotal,
            $declRatified, $declTotal,
            $opinionsTotal,
            $ballotsActive, $ballotsTotal
        );

        // Top ratified declarations
        $stmtTop = $pdo->prepare("
            SELECT d.declaration_id, d.title, d.status, d.yes_count, d.vote_count, d.ratified_at,
                   ig.name AS group_name
            FROM declarations d
            LEFT JOIN idea_groups ig ON ig.id = d.group_id
            WHERE d.scope_type = 'town' AND d.scope_id = :sid AND d.status = 'ratified'
            ORDER BY d.yes_count DESC, d.ratified_at DESC
            LIMIT 10
        ");
        $stmtTop->execute([':sid' => $scopeId]);
        $topDeclarations = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

        return [
            'town_id'    => $townId,
            'town_name'  => $town['name'],
            'scope_id'   => $scopeId,
            'groups'     => [
                'total'              => $groupsTotal,
                'active'             => $groupsActive,
                'with_declarations'  => $groupsWithDecl,
            ],
            'declarations' => [
                'total'    => $declTotal,
                'ratified' => $declRatified,
                'voting'   => $declVoting,
            ],
            'opinions'         => ['total' => $opinionsTotal],
            'ballots'          => ['total' => $ballotsTotal, 'active' => $ballotsActive],
            'pulse_score'      => $pulseScore,
            'top_declarations' => $topDeclarations,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  2. getStateRollup                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Aggregate all town roll-ups for a state.
     *
     * @param PDO    $pdo
     * @param string $stateAbbr  e.g. 'ct'
     * @return array
     */
    public static function getStateRollup(PDO $pdo, string $stateAbbr): array
    {
        $stateAbbr = strtolower($stateAbbr);

        // Get state info
        $stmt = $pdo->prepare("SELECT id, name, abbr FROM states WHERE LOWER(abbr) = :abbr LIMIT 1");
        $stmt->execute([':abbr' => $stateAbbr]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$state) {
            return ['error' => 'State not found'];
        }

        $stateId = (int) $state['id'];

        // Get all towns in this state
        $stmtT = $pdo->prepare("SELECT id, name, slug FROM towns WHERE state_id = :sid ORDER BY name");
        $stmtT->execute([':sid' => $stateId]);
        $towns = $stmtT->fetchAll(PDO::FETCH_ASSOC);
        $totalTowns = count($towns);

        // Aggregate across all towns
        $totalGroups = 0;
        $totalGroupsActive = 0;
        $totalDecl = 0;
        $totalDeclRatified = 0;
        $totalOpinions = 0;
        $activeTowns = [];

        foreach ($towns as $town) {
            $rollup = self::getTownRollup($pdo, (int) $town['id']);
            if (isset($rollup['error'])) continue;

            $totalGroups       += $rollup['groups']['total'];
            $totalGroupsActive += $rollup['groups']['active'];
            $totalDecl         += $rollup['declarations']['total'];
            $totalDeclRatified += $rollup['declarations']['ratified'];
            $totalOpinions     += $rollup['opinions']['total'];

            $hasActivity = ($rollup['groups']['total'] > 0
                         || $rollup['declarations']['total'] > 0
                         || $rollup['opinions']['total'] > 0
                         || $rollup['ballots']['total'] > 0);

            if ($hasActivity) {
                $activeTowns[] = [
                    'town_id'     => (int) $town['id'],
                    'town_name'   => $town['name'],
                    'slug'        => $town['slug'],
                    'pulse_score' => $rollup['pulse_score'],
                    'declarations' => $rollup['declarations']['total'],
                    'groups'      => $rollup['groups']['total'],
                ];
            }
        }

        // Also get state-scoped declarations directly
        $stmtSD = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'ratified' THEN 1 ELSE 0 END) AS ratified
            FROM declarations
            WHERE scope_type = 'state' AND LOWER(scope_id) = :abbr
        ");
        $stmtSD->execute([':abbr' => $stateAbbr]);
        $stateDecl = $stmtSD->fetch(PDO::FETCH_ASSOC);
        $totalDecl         += (int) ($stateDecl['total'] ?? 0);
        $totalDeclRatified += (int) ($stateDecl['ratified'] ?? 0);

        // State-level opinions
        $stmtSO = $pdo->prepare("
            SELECT COUNT(*) FROM public_opinions
            WHERE scope_type = 'state' AND LOWER(scope_id) = :abbr
        ");
        $stmtSO->execute([':abbr' => $stateAbbr]);
        $totalOpinions += (int) $stmtSO->fetchColumn();

        // Sort active towns by pulse score descending
        usort($activeTowns, fn($a, $b) => $b['pulse_score'] <=> $a['pulse_score']);

        // State pulse
        $pulseScore = self::calculatePulse(
            $totalGroupsActive, max($totalGroups, 1),
            $totalDeclRatified, max($totalDecl, 1),
            $totalOpinions,
            0, 1 // ballots counted in town roll-ups
        );

        // Top declarations across town + state
        $stmtTopD = $pdo->prepare("
            SELECT d.declaration_id, d.title, d.scope_type, d.scope_id,
                   d.yes_count, d.vote_count, d.ratified_at,
                   ig.name AS group_name
            FROM declarations d
            LEFT JOIN idea_groups ig ON ig.id = d.group_id
            WHERE d.status = 'ratified'
              AND (
                (d.scope_type = 'state' AND LOWER(d.scope_id) = :abbr)
                OR (d.scope_type = 'town' AND LOWER(d.scope_id) LIKE :prefix)
              )
            ORDER BY d.yes_count DESC, d.ratified_at DESC
            LIMIT 10
        ");
        $stmtTopD->execute([':abbr' => $stateAbbr, ':prefix' => $stateAbbr . '-%']);
        $topDeclarations = $stmtTopD->fetchAll(PDO::FETCH_ASSOC);

        return [
            'state_abbr'  => strtoupper($stateAbbr),
            'state_name'  => $state['name'],
            'state_id'    => $stateId,
            'towns'       => [
                'total'  => $totalTowns,
                'active' => count($activeTowns),
            ],
            'groups'       => ['total' => $totalGroups, 'active' => $totalGroupsActive],
            'declarations' => ['total' => $totalDecl, 'ratified' => $totalDeclRatified],
            'opinions'     => ['total' => $totalOpinions],
            'pulse_score'  => $pulseScore,
            'top_declarations' => $topDeclarations,
            'active_towns'     => $activeTowns,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  3. getFederalRollup                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Aggregate all state roll-ups into a federal summary.
     *
     * To avoid N+1 query explosion, this uses direct aggregation queries
     * rather than calling getStateRollup() for each state.
     *
     * @param PDO $pdo
     * @return array
     */
    public static function getFederalRollup(PDO $pdo): array
    {
        // Total states
        $stmtStates = $pdo->query("SELECT COUNT(*) FROM states");
        $totalStates = (int) $stmtStates->fetchColumn();

        // Groups total
        $stmtG = $pdo->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status IN ('active','crystallizing') THEN 1 ELSE 0 END) AS active_count
            FROM idea_groups
            WHERE status IN ('forming','active','crystallizing','crystallized')
        ");
        $groups = $stmtG->fetch(PDO::FETCH_ASSOC);
        $totalGroups  = (int) ($groups['total'] ?? 0);
        $activeGroups = (int) ($groups['active_count'] ?? 0);

        // Declarations total
        $stmtD = $pdo->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'ratified' THEN 1 ELSE 0 END) AS ratified
            FROM declarations
        ");
        $decl = $stmtD->fetch(PDO::FETCH_ASSOC);
        $totalDecl    = (int) ($decl['total'] ?? 0);
        $totalRatified = (int) ($decl['ratified'] ?? 0);

        // Opinions total
        $stmtO = $pdo->query("SELECT COUNT(*) FROM public_opinions");
        $totalOpinions = (int) $stmtO->fetchColumn();

        // Ballots total
        $stmtB = $pdo->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN active = 1 AND closed_at IS NULL THEN 1 ELSE 0 END) AS active_count
            FROM polls
        ");
        $ballots = $stmtB->fetch(PDO::FETCH_ASSOC);
        $totalBallots  = (int) ($ballots['total'] ?? 0);
        $activeBallots = (int) ($ballots['active_count'] ?? 0);

        // Pulse
        $pulseScore = self::calculatePulse(
            $activeGroups, max($totalGroups, 1),
            $totalRatified, max($totalDecl, 1),
            $totalOpinions,
            $activeBallots, max($totalBallots, 1)
        );

        // Top declarations nationally
        $stmtTopD = $pdo->query("
            SELECT d.declaration_id, d.title, d.scope_type, d.scope_id,
                   d.yes_count, d.vote_count, d.ratified_at,
                   ig.name AS group_name
            FROM declarations d
            LEFT JOIN idea_groups ig ON ig.id = d.group_id
            WHERE d.status = 'ratified'
            ORDER BY d.yes_count DESC, d.ratified_at DESC
            LIMIT 15
        ");
        $topDeclarations = $stmtTopD->fetchAll(PDO::FETCH_ASSOC);

        // Active states: states that have any civic activity
        $activeStates = [];
        $stmtAS = $pdo->query("
            SELECT s.id, s.name, s.abbr,
                   (SELECT COUNT(*) FROM idea_groups g
                    WHERE g.state_id = s.id
                      AND g.status IN ('forming','active','crystallizing','crystallized')) AS group_count,
                   (SELECT COUNT(*) FROM declarations d
                    WHERE (d.scope_type = 'state' AND LOWER(d.scope_id) = LOWER(s.abbr))
                       OR (d.scope_type = 'town' AND LOWER(d.scope_id) LIKE CONCAT(LOWER(s.abbr), '-%'))) AS decl_count
            FROM states s
            HAVING group_count > 0 OR decl_count > 0
            ORDER BY group_count + decl_count DESC
        ");
        $activeStatesRows = $stmtAS->fetchAll(PDO::FETCH_ASSOC);

        foreach ($activeStatesRows as $as) {
            $activeStates[] = [
                'state_id'    => (int) $as['id'],
                'state_name'  => $as['name'],
                'state_abbr'  => strtoupper($as['abbr']),
                'groups'      => (int) $as['group_count'],
                'declarations' => (int) $as['decl_count'],
            ];
        }

        return [
            'states'       => ['total' => $totalStates, 'active' => count($activeStates)],
            'groups'       => ['total' => $totalGroups, 'active' => $activeGroups],
            'declarations' => ['total' => $totalDecl, 'ratified' => $totalRatified],
            'opinions'     => ['total' => $totalOpinions],
            'ballots'      => ['total' => $totalBallots, 'active' => $activeBallots],
            'pulse_score'  => $pulseScore,
            'top_declarations' => $topDeclarations,
            'active_states'    => $activeStates,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  4. findConvergence                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Find declarations on similar topics across jurisdictions.
     *
     * Uses keyword extraction from declaration titles and groups by
     * matching keywords. Simple approach for now.
     *
     * @param PDO         $pdo
     * @param string      $scopeType  federal|state|town
     * @param string|null $scopeId
     * @return array  Array of convergence signals
     */
    public static function findConvergence(PDO $pdo, string $scopeType, ?string $scopeId = null): array
    {
        // Get ratified declarations at the target scope level or below
        $sql = "SELECT d.declaration_id, d.title, d.scope_type, d.scope_id,
                       d.yes_count, d.vote_count, ig.name AS group_name
                FROM declarations d
                LEFT JOIN idea_groups ig ON ig.id = d.group_id
                WHERE d.status = 'ratified'";
        $params = [];

        if ($scopeType === 'state' && $scopeId) {
            $abbr = strtolower($scopeId);
            $sql .= " AND (
                (d.scope_type = 'state' AND LOWER(d.scope_id) = :abbr)
                OR (d.scope_type = 'town' AND LOWER(d.scope_id) LIKE :prefix)
            )";
            $params[':abbr'] = $abbr;
            $params[':prefix'] = $abbr . '-%';
        } elseif ($scopeType === 'town' && $scopeId) {
            // For town scope, look at sibling towns in the same state
            $parts = explode('-', $scopeId, 2);
            $abbr = strtolower($parts[0]);
            $sql .= " AND d.scope_type = 'town' AND LOWER(d.scope_id) LIKE :prefix";
            $params[':prefix'] = $abbr . '-%';
        }
        // federal: get all ratified declarations

        $sql .= " ORDER BY d.ratified_at DESC LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $declarations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($declarations)) {
            return [];
        }

        // Extract keywords from titles (simple: split into words, filter stopwords)
        $stopwords = ['the','a','an','and','or','but','in','on','at','to','for',
                      'of','with','by','from','is','are','was','were','be','been',
                      'being','have','has','had','do','does','did','will','shall',
                      'should','would','could','may','might','must','can','this',
                      'that','these','those','it','its','we','our','us','all','no',
                      'not','into','as','up','out','if','then','than','so','too'];

        // Build keyword -> declarations map
        $keywordMap = [];
        foreach ($declarations as $decl) {
            $words = preg_split('/[\s\-:,\.!?]+/', strtolower($decl['title']));
            $words = array_filter($words, function ($w) use ($stopwords) {
                return strlen($w) > 2 && !in_array($w, $stopwords, true);
            });
            foreach ($words as $word) {
                $keywordMap[$word][] = $decl;
            }
        }

        // Find keywords that appear in declarations from multiple jurisdictions
        $convergences = [];
        foreach ($keywordMap as $keyword => $decls) {
            $jurisdictions = [];
            foreach ($decls as $d) {
                $key = $d['scope_type'] . ':' . ($d['scope_id'] ?? 'federal');
                if (!isset($jurisdictions[$key])) {
                    $jurisdictions[$key] = [];
                }
                $jurisdictions[$key][] = [
                    'declaration_id' => (int) $d['declaration_id'],
                    'title'          => $d['title'],
                    'scope_type'     => $d['scope_type'],
                    'scope_id'       => $d['scope_id'],
                ];
            }

            if (count($jurisdictions) >= 2) {
                $convergences[] = [
                    'topic'             => ucfirst($keyword),
                    'declaration_count' => count($decls),
                    'jurisdiction_count' => count($jurisdictions),
                    'jurisdictions'     => array_keys($jurisdictions),
                    'declarations'      => array_map(function ($d) {
                        return [
                            'declaration_id' => (int) $d['declaration_id'],
                            'title'          => $d['title'],
                            'scope'          => $d['scope_type'] . ':' . ($d['scope_id'] ?? 'federal'),
                        ];
                    }, $decls),
                ];
            }
        }

        // Sort by number of jurisdictions then declaration count
        usort($convergences, function ($a, $b) {
            if ($b['jurisdiction_count'] !== $a['jurisdiction_count']) {
                return $b['jurisdiction_count'] - $a['jurisdiction_count'];
            }
            return $b['declaration_count'] - $a['declaration_count'];
        });

        return array_slice($convergences, 0, 20);
    }

    /* ------------------------------------------------------------------ */
    /*  5. beamToDesk                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Generate representative targeting data for a scope.
     *
     * @param PDO    $pdo
     * @param string $scopeType  federal|state|town
     * @param string $scopeId
     * @return array
     */
    public static function beamToDesk(PDO $pdo, string $scopeType, string $scopeId): array
    {
        // Get ratified declarations for this scope
        $stmtD = $pdo->prepare("
            SELECT d.declaration_id, d.title, d.body, d.yes_count, d.vote_count,
                   d.ratified_at, ig.name AS group_name
            FROM declarations d
            LEFT JOIN idea_groups ig ON ig.id = d.group_id
            WHERE d.status = 'ratified'
              AND d.scope_type = :scope_type AND d.scope_id = :scope_id
            ORDER BY d.yes_count DESC
        ");
        $stmtD->execute([':scope_type' => $scopeType, ':scope_id' => $scopeId]);
        $declarations = $stmtD->fetchAll(PDO::FETCH_ASSOC);

        // Get opinion support for each declaration
        $opinionSupport = [];
        foreach ($declarations as $decl) {
            $sentiment = Opinion::getSentiment($pdo, 'declaration', (int) $decl['declaration_id']);
            $opinionSupport[(int) $decl['declaration_id']] = $sentiment;
        }

        // Get representatives if elected_officials table exists
        $representatives = [];
        if (self::tableExists($pdo, 'elected_officials')) {
            if ($scopeType === 'federal') {
                $stmtR = $pdo->query("
                    SELECT official_id, full_name, position, party, state
                    FROM elected_officials
                    WHERE is_current = 1
                    ORDER BY position, state
                    LIMIT 20
                ");
                $representatives = $stmtR->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($scopeType === 'state') {
                $stmtR = $pdo->prepare("
                    SELECT official_id, full_name, position, party, state
                    FROM elected_officials
                    WHERE is_current = 1 AND LOWER(state) = LOWER(:state)
                    ORDER BY position
                ");
                $stmtR->execute([':state' => $scopeId]);
                $representatives = $stmtR->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Build formatted message
        $message = self::buildCivicMessage($scopeType, $scopeId, $declarations, $opinionSupport);

        return [
            'scope_type'       => $scopeType,
            'scope_id'         => $scopeId,
            'declarations'     => $declarations,
            'opinion_support'  => $opinionSupport,
            'representatives'  => $representatives,
            'message'          => $message,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helper: build civic mandate message                                */
    /* ------------------------------------------------------------------ */

    private static function buildCivicMessage(
        string $scopeType,
        string $scopeId,
        array $declarations,
        array $opinionSupport
    ): string {
        if (empty($declarations)) {
            return "No ratified declarations to report for this scope.";
        }

        $scopeLabel = ucfirst($scopeType);
        if ($scopeType === 'town') {
            $parts = explode('-', $scopeId, 2);
            $scopeLabel = ucfirst($parts[1] ?? $scopeId) . ', ' . strtoupper($parts[0] ?? '');
        } elseif ($scopeType === 'state') {
            $scopeLabel = strtoupper($scopeId);
        }

        $lines = [];
        $lines[] = "CIVIC MANDATE FROM THE PEOPLE OF $scopeLabel";
        $lines[] = str_repeat('=', 50);
        $lines[] = '';
        $lines[] = 'The following declarations have been ratified through';
        $lines[] = 'deliberative civic process at The People\'s Branch:';
        $lines[] = '';

        foreach ($declarations as $i => $decl) {
            $num = $i + 1;
            $lines[] = "$num. {$decl['title']}";
            $lines[] = "   Votes: {$decl['yes_count']}/{$decl['vote_count']}";

            $sentiment = $opinionSupport[(int) $decl['declaration_id']] ?? null;
            if ($sentiment && $sentiment['total'] > 0) {
                $agreeP = round(($sentiment['agree'] / $sentiment['total']) * 100);
                $lines[] = "   Public opinion: {$agreeP}% agree ({$sentiment['total']} opinions)";
            }

            if ($decl['ratified_at']) {
                $lines[] = '   Ratified: ' . date('F j, Y', strtotime($decl['ratified_at']));
            }
            $lines[] = '';
        }

        $lines[] = str_repeat('-', 50);
        $lines[] = 'Generated by The People\'s Branch (4tpb.org)';
        $lines[] = 'Date: ' . date('F j, Y');

        return implode("\n", $lines);
    }
}
