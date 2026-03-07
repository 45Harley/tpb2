<?php
/**
 * Ballot — helper class for creating, reading, voting, and tallying polls.
 *
 * All methods are static. Every write operation uses a transaction.
 *
 * Depends on tables: polls, poll_options, poll_votes
 */
class Ballot
{
    /* ------------------------------------------------------------------ */
    /*  1. create                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Create a poll (and its options for multi/ranked types).
     *
     * @param PDO   $pdo
     * @param array $data  Keys: question(required), slug, scope_type, scope_id,
     *                     vote_type, threshold_type, quorum_type, quorum_value,
     *                     created_by, source_type, source_id, options(array),
     *                     parent_poll_id, round, poll_type
     * @return array ['poll_id'=>int, 'options'=>array] or ['error'=>string]
     */
    public static function create(PDO $pdo, array $data): array
    {
        // --- validation ---
        if (empty($data['question'])) {
            return ['error' => 'Question is required.'];
        }

        $voteType = $data['vote_type'] ?? 'yes_no';
        $validVoteTypes = ['yes_no', 'yes_no_novote', 'multi_choice', 'ranked_choice'];
        if (!in_array($voteType, $validVoteTypes, true)) {
            return ['error' => "Invalid vote_type: $voteType"];
        }

        // multi/ranked require at least 2 options
        if (in_array($voteType, ['multi_choice', 'ranked_choice'], true)) {
            if (empty($data['options']) || count($data['options']) < 2) {
                return ['error' => 'multi_choice and ranked_choice require at least 2 options.'];
            }
        }

        $slug = !empty($data['slug'])
            ? $data['slug']
            : self::generateSlug($data['question']);

        try {
            $pdo->beginTransaction();

            $sql = "INSERT INTO polls
                    (question, slug, scope_type, scope_id, vote_type,
                     threshold_type, quorum_type, quorum_value,
                     created_by, source_type, source_id,
                     parent_poll_id, round, poll_type, active, created_at, updated_at)
                    VALUES
                    (:question, :slug, :scope_type, :scope_id, :vote_type,
                     :threshold_type, :quorum_type, :quorum_value,
                     :created_by, :source_type, :source_id,
                     :parent_poll_id, :round, :poll_type, 1, NOW(), NOW())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':question'       => $data['question'],
                ':slug'           => $slug,
                ':scope_type'     => $data['scope_type']     ?? 'federal',
                ':scope_id'       => $data['scope_id']       ?? null,
                ':vote_type'      => $voteType,
                ':threshold_type' => $data['threshold_type'] ?? 'majority',
                ':quorum_type'    => $data['quorum_type']    ?? 'none',
                ':quorum_value'   => $data['quorum_value']   ?? null,
                ':created_by'     => $data['created_by']     ?? null,
                ':source_type'    => $data['source_type']    ?? null,
                ':source_id'      => $data['source_id']      ?? null,
                ':parent_poll_id' => $data['parent_poll_id'] ?? null,
                ':round'          => $data['round']          ?? 1,
                ':poll_type'      => $data['poll_type']      ?? null,
            ]);

            $pollId = (int) $pdo->lastInsertId();
            $options = [];

            // Insert options for multi/ranked
            if (in_array($voteType, ['multi_choice', 'ranked_choice'], true) && !empty($data['options'])) {
                $optStmt = $pdo->prepare(
                    "INSERT INTO poll_options (poll_id, option_text, option_order, created_at)
                     VALUES (:poll_id, :option_text, :option_order, NOW())"
                );
                foreach ($data['options'] as $i => $text) {
                    $optStmt->execute([
                        ':poll_id'      => $pollId,
                        ':option_text'  => $text,
                        ':option_order' => $i + 1,
                    ]);
                    $options[] = [
                        'option_id'    => (int) $pdo->lastInsertId(),
                        'option_text'  => $text,
                        'option_order' => $i + 1,
                    ];
                }
            }

            $pdo->commit();
            return ['poll_id' => $pollId, 'options' => $options];

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['error' => 'Failed to create poll: ' . $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  2. get                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Get a poll row plus its options (if multi/ranked).
     *
     * @return array|null
     */
    public static function get(PDO $pdo, int $pollId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM polls WHERE poll_id = :id");
        $stmt->execute([':id' => $pollId]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$poll) {
            return null;
        }

        $poll['options'] = [];
        if (in_array($poll['vote_type'], ['multi_choice', 'ranked_choice'], true)) {
            $optStmt = $pdo->prepare(
                "SELECT * FROM poll_options WHERE poll_id = :id ORDER BY option_order"
            );
            $optStmt->execute([':id' => $pollId]);
            $poll['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $poll;
    }

    /* ------------------------------------------------------------------ */
    /*  3. vote                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Cast or update a vote.
     *
     * @param PDO   $pdo
     * @param int   $pollId
     * @param int   $userId
     * @param array $voteData  Keys depend on vote_type (see class doc)
     * @param bool  $isRep     Whether this is a representative vote
     * @return array ['success'=>true,'action'=>'created'|'updated'] or ['error'=>string]
     */
    public static function vote(PDO $pdo, int $pollId, int $userId, array $voteData, bool $isRep = false): array
    {
        // Fetch poll
        $poll = self::get($pdo, $pollId);
        if (!$poll) {
            return ['error' => 'Poll not found.'];
        }
        if (!$poll['active']) {
            return ['error' => 'Poll is not active.'];
        }
        if ($poll['closed_at'] !== null) {
            return ['error' => 'Poll is closed.'];
        }

        $voteType = $poll['vote_type'];

        try {
            $pdo->beginTransaction();

            switch ($voteType) {
                case 'yes_no':
                case 'yes_no_novote':
                    $result = self::voteYesNo($pdo, $pollId, $userId, $voteData, $voteType, $isRep);
                    break;

                case 'multi_choice':
                    $result = self::voteMultiChoice($pdo, $pollId, $userId, $voteData, $poll['options'], $isRep);
                    break;

                case 'ranked_choice':
                    $result = self::voteRankedChoice($pdo, $pollId, $userId, $voteData, $poll['options'], $isRep);
                    break;

                default:
                    $pdo->rollBack();
                    return ['error' => "Unsupported vote_type: $voteType"];
            }

            if (isset($result['error'])) {
                $pdo->rollBack();
                return $result;
            }

            $pdo->commit();
            return $result;

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['error' => 'Vote failed: ' . $e->getMessage()];
        }
    }

    /* ---- vote sub-handlers ---- */

    private static function voteYesNo(PDO $pdo, int $pollId, int $userId, array $voteData, string $voteType, bool $isRep): array
    {
        $choice = $voteData['vote_choice'] ?? '';
        $allowed = ['yea', 'nay', 'abstain'];
        if ($voteType === 'yes_no_novote') {
            $allowed[] = 'novote';
        }
        if (!in_array($choice, $allowed, true)) {
            return ['error' => "Invalid vote_choice '$choice' for $voteType."];
        }

        // Check existing vote
        $existing = $pdo->prepare(
            "SELECT poll_vote_id FROM poll_votes WHERE poll_id = :pid AND user_id = :uid LIMIT 1"
        );
        $existing->execute([':pid' => $pollId, ':uid' => $userId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $upd = $pdo->prepare(
                "UPDATE poll_votes SET vote_choice = :choice, is_rep_vote = :rep, updated_at = NOW()
                 WHERE poll_vote_id = :vid"
            );
            $upd->execute([':choice' => $choice, ':rep' => (int) $isRep, ':vid' => $row['poll_vote_id']]);
            return ['success' => true, 'action' => 'updated'];
        }

        $ins = $pdo->prepare(
            "INSERT INTO poll_votes (poll_id, user_id, vote_choice, is_rep_vote, voted_at, updated_at)
             VALUES (:pid, :uid, :choice, :rep, NOW(), NOW())"
        );
        $ins->execute([':pid' => $pollId, ':uid' => $userId, ':choice' => $choice, ':rep' => (int) $isRep]);
        return ['success' => true, 'action' => 'created'];
    }

    private static function voteMultiChoice(PDO $pdo, int $pollId, int $userId, array $voteData, array $options, bool $isRep): array
    {
        $optionId = $voteData['option_id'] ?? null;
        if (!$optionId) {
            return ['error' => 'option_id is required for multi_choice.'];
        }

        // Verify option belongs to this poll
        $validIds = array_column($options, 'option_id');
        if (!in_array((int) $optionId, array_map('intval', $validIds), true)) {
            return ['error' => 'option_id does not belong to this poll.'];
        }

        // Check existing vote
        $existing = $pdo->prepare(
            "SELECT poll_vote_id FROM poll_votes WHERE poll_id = :pid AND user_id = :uid LIMIT 1"
        );
        $existing->execute([':pid' => $pollId, ':uid' => $userId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $upd = $pdo->prepare(
                "UPDATE poll_votes SET option_id = :oid, is_rep_vote = :rep, updated_at = NOW()
                 WHERE poll_vote_id = :vid"
            );
            $upd->execute([':oid' => $optionId, ':rep' => (int) $isRep, ':vid' => $row['poll_vote_id']]);
            return ['success' => true, 'action' => 'updated'];
        }

        $ins = $pdo->prepare(
            "INSERT INTO poll_votes (poll_id, user_id, option_id, is_rep_vote, voted_at, updated_at)
             VALUES (:pid, :uid, :oid, :rep, NOW(), NOW())"
        );
        $ins->execute([':pid' => $pollId, ':uid' => $userId, ':oid' => $optionId, ':rep' => (int) $isRep]);
        return ['success' => true, 'action' => 'created'];
    }

    private static function voteRankedChoice(PDO $pdo, int $pollId, int $userId, array $voteData, array $options, bool $isRep): array
    {
        $rankings = $voteData['rankings'] ?? [];
        if (empty($rankings)) {
            return ['error' => 'rankings array is required for ranked_choice.'];
        }

        $validIds = array_map('intval', array_column($options, 'option_id'));
        foreach ($rankings as $optId => $rank) {
            if (!in_array((int) $optId, $validIds, true)) {
                return ['error' => "option_id $optId does not belong to this poll."];
            }
        }

        // Determine action before deleting
        $existing = $pdo->prepare(
            "SELECT COUNT(*) FROM poll_votes WHERE poll_id = :pid AND user_id = :uid"
        );
        $existing->execute([':pid' => $pollId, ':uid' => $userId]);
        $action = ((int) $existing->fetchColumn() > 0) ? 'updated' : 'created';

        // Delete all existing rankings for this user/poll
        $del = $pdo->prepare("DELETE FROM poll_votes WHERE poll_id = :pid AND user_id = :uid");
        $del->execute([':pid' => $pollId, ':uid' => $userId]);

        // Insert new rankings
        $ins = $pdo->prepare(
            "INSERT INTO poll_votes (poll_id, user_id, option_id, rank_position, is_rep_vote, voted_at, updated_at)
             VALUES (:pid, :uid, :oid, :rank, :rep, NOW(), NOW())"
        );
        foreach ($rankings as $optId => $rank) {
            $ins->execute([
                ':pid'  => $pollId,
                ':uid'  => $userId,
                ':oid'  => (int) $optId,
                ':rank' => (int) $rank,
                ':rep'  => (int) $isRep,
            ]);
        }

        return ['success' => true, 'action' => $action];
    }

    /* ------------------------------------------------------------------ */
    /*  4. tally                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Tally votes for a poll. Return shape varies by vote_type.
     */
    public static function tally(PDO $pdo, int $pollId): array
    {
        $poll = self::get($pdo, $pollId);
        if (!$poll) {
            return ['error' => 'Poll not found.'];
        }

        switch ($poll['vote_type']) {
            case 'yes_no':
            case 'yes_no_novote':
                return self::tallyYesNo($pdo, $pollId, $poll);

            case 'multi_choice':
                return self::tallyMultiChoice($pdo, $pollId, $poll);

            case 'ranked_choice':
                return self::tallyRankedChoice($pdo, $pollId, $poll);

            default:
                return ['error' => 'Unknown vote_type: ' . $poll['vote_type']];
        }
    }

    private static function tallyYesNo(PDO $pdo, int $pollId, array $poll): array
    {
        $stmt = $pdo->prepare(
            "SELECT vote_choice, COUNT(*) AS cnt
             FROM poll_votes WHERE poll_id = :pid
             GROUP BY vote_choice"
        );
        $stmt->execute([':pid' => $pollId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $yea     = (int) ($rows['yea']     ?? 0);
        $nay     = (int) ($rows['nay']     ?? 0);
        $abstain = (int) ($rows['abstain'] ?? 0);
        $novote  = (int) ($rows['novote']  ?? 0);
        $total   = $yea + $nay + $abstain + $novote;

        $result = [
            'total_votes'   => $total,
            'yea'           => $yea,
            'nay'           => $nay,
            'abstain'       => $abstain,
            'threshold_met' => self::checkThreshold($yea, $nay, $total, $poll['threshold_type'] ?? 'majority'),
            'quorum_met'    => self::checkQuorum($total, $poll['quorum_type'] ?? 'none', $poll['quorum_value']),
        ];

        if ($poll['vote_type'] === 'yes_no_novote') {
            $result['novote'] = $novote;
        }

        return $result;
    }

    private static function tallyMultiChoice(PDO $pdo, int $pollId, array $poll): array
    {
        // Total distinct voters
        $totalStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM poll_votes WHERE poll_id = :pid"
        );
        $totalStmt->execute([':pid' => $pollId]);
        $totalVoters = (int) $totalStmt->fetchColumn();

        // Votes per option
        $stmt = $pdo->prepare(
            "SELECT po.option_id, po.option_text, po.option_order,
                    COUNT(pv.poll_vote_id) AS vote_count
             FROM poll_options po
             LEFT JOIN poll_votes pv ON pv.option_id = po.option_id AND pv.poll_id = po.poll_id
             WHERE po.poll_id = :pid
             GROUP BY po.option_id, po.option_text, po.option_order
             ORDER BY vote_count DESC, po.option_order ASC"
        );
        $stmt->execute([':pid' => $pollId]);
        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $winner = null;
        $topCount = 0;
        foreach ($options as &$opt) {
            $opt['vote_count'] = (int) $opt['vote_count'];
            if ($opt['vote_count'] > $topCount) {
                $topCount = $opt['vote_count'];
                $winner = $opt['option_text'];
            }
        }
        unset($opt);

        // Threshold: winner needs to pass threshold relative to total
        $thresholdMet = false;
        if ($totalVoters > 0) {
            $thresholdMet = self::checkThreshold($topCount, $totalVoters - $topCount, $totalVoters, $poll['threshold_type'] ?? 'plurality');
        }

        return [
            'options'       => $options,
            'total_voters'  => $totalVoters,
            'winner'        => $winner,
            'threshold_met' => $thresholdMet,
            'quorum_met'    => self::checkQuorum($totalVoters, $poll['quorum_type'] ?? 'none', $poll['quorum_value']),
        ];
    }

    private static function tallyRankedChoice(PDO $pdo, int $pollId, array $poll): array
    {
        // Get all ballots: user_id => [option_id => rank]
        $stmt = $pdo->prepare(
            "SELECT user_id, option_id, rank_position
             FROM poll_votes WHERE poll_id = :pid
             ORDER BY user_id, rank_position"
        );
        $stmt->execute([':pid' => $pollId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build ballots: user_id => [rank => option_id] sorted by rank
        $ballots = [];
        foreach ($rows as $r) {
            $ballots[(int) $r['user_id']][(int) $r['rank_position']] = (int) $r['option_id'];
        }
        // Sort each ballot by rank
        foreach ($ballots as &$b) {
            ksort($b);
            $b = array_values($b); // re-index to 0-based ordered list
        }
        unset($b);

        $totalVoters = count($ballots);
        if ($totalVoters === 0) {
            return [
                'rounds'           => [],
                'winner'           => null,
                'decided_in_round' => 0,
                'total_voters'     => 0,
                'quorum_met'       => self::checkQuorum(0, $poll['quorum_type'] ?? 'none', $poll['quorum_value']),
            ];
        }

        // Build option name lookup
        $optNames = [];
        foreach ($poll['options'] as $o) {
            $optNames[(int) $o['option_id']] = $o['option_text'];
        }

        $eliminated = [];
        $rounds     = [];
        $winner     = null;
        $roundNum   = 0;
        $majority   = floor($totalVoters / 2) + 1;

        while (true) {
            $roundNum++;
            $counts = [];

            // Count first-choice votes (skipping eliminated)
            foreach ($ballots as $ballot) {
                foreach ($ballot as $optId) {
                    if (!isset($eliminated[$optId])) {
                        $counts[$optId] = ($counts[$optId] ?? 0) + 1;
                        break; // only count first non-eliminated choice
                    }
                }
            }

            // Record this round
            $roundData = [];
            foreach ($counts as $optId => $cnt) {
                $roundData[] = [
                    'option_id'   => $optId,
                    'option_text' => $optNames[$optId] ?? "Option $optId",
                    'votes'       => $cnt,
                ];
            }
            usort($roundData, fn($a, $b) => $b['votes'] - $a['votes']);
            $rounds[] = ['round' => $roundNum, 'results' => $roundData];

            // Check for winner (majority of remaining valid ballots)
            if (!empty($roundData) && $roundData[0]['votes'] >= $majority) {
                $winner = $roundData[0]['option_text'];
                break;
            }

            // If only one (or zero) candidates left, declare winner or tie
            if (count($counts) <= 1) {
                $winner = !empty($roundData) ? $roundData[0]['option_text'] : null;
                break;
            }

            // Eliminate candidate with fewest votes
            $minVotes = PHP_INT_MAX;
            $eliminateId = null;
            foreach ($counts as $optId => $cnt) {
                if ($cnt < $minVotes) {
                    $minVotes = $cnt;
                    $eliminateId = $optId;
                }
            }
            $eliminated[$eliminateId] = true;

            // Safety: prevent infinite loop if all eliminated
            if (count($eliminated) >= count($optNames)) {
                break;
            }
        }

        return [
            'rounds'           => $rounds,
            'winner'           => $winner,
            'decided_in_round' => $roundNum,
            'total_voters'     => $totalVoters,
            'quorum_met'       => self::checkQuorum($totalVoters, $poll['quorum_type'] ?? 'none', $poll['quorum_value']),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  5. checkThreshold                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Check if yea votes meet the given threshold.
     *
     * @param int    $yea   Votes in favor
     * @param int    $nay   Votes against
     * @param int    $total Total votes cast
     * @param string $type  plurality|majority|three_fifths|two_thirds|three_quarters|unanimous
     * @return bool
     */
    public static function checkThreshold(int $yea, int $nay, int $total, string $type): bool
    {
        if ($total === 0) {
            return false;
        }

        $ratio = $yea / $total;

        switch ($type) {
            case 'plurality':
                return $yea > $nay;
            case 'majority':
                return $ratio > 0.5;
            case 'three_fifths':
                return $ratio >= 0.6;
            case 'two_thirds':
                return $ratio >= (2 / 3);
            case 'three_quarters':
                return $ratio >= 0.75;
            case 'unanimous':
                return $yea === $total;
            default:
                return $yea > $nay; // fallback to plurality
        }
    }

    /* ------------------------------------------------------------------ */
    /*  6. checkQuorum                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Check if a quorum is met.
     *
     * @param int         $totalVoters  Number of people who voted
     * @param string      $type         none|minimum|percent
     * @param int|null    $value        Threshold value (count for minimum, ignored for none/percent)
     * @return bool
     */
    public static function checkQuorum(int $totalVoters, string $type, $value): bool
    {
        switch ($type) {
            case 'none':
                return true;
            case 'minimum':
                return $totalVoters >= (int) $value;
            case 'percent':
                // Caller must provide context (eligible voters) — return true as default
                return true;
            default:
                return true;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  7. listByScope                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * List polls filtered by scope, with total vote count.
     *
     * @param PDO         $pdo
     * @param string      $scopeType
     * @param int|null    $scopeId
     * @param bool        $activeOnly
     * @return array
     */
    public static function listByScope(PDO $pdo, string $scopeType, ?int $scopeId = null, bool $activeOnly = true): array
    {
        $sql = "SELECT p.*,
                       COUNT(DISTINCT pv.user_id) AS total_votes
                FROM polls p
                LEFT JOIN poll_votes pv ON pv.poll_id = p.poll_id
                WHERE p.scope_type = :scope_type";
        $params = [':scope_type' => $scopeType];

        if ($scopeId !== null) {
            $sql .= " AND p.scope_id = :scope_id";
            $params[':scope_id'] = $scopeId;
        }

        if ($activeOnly) {
            $sql .= " AND p.active = 1 AND p.closed_at IS NULL";
        }

        $sql .= " GROUP BY p.poll_id ORDER BY p.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ------------------------------------------------------------------ */
    /*  8. generateSlug                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Generate a URL-friendly slug from text.
     * Lowercase, strip non-alphanumeric, hyphens for spaces, append 6-char hash. Max 100 chars.
     *
     * @param string $text
     * @return string
     */
    private static function generateSlug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Truncate base to leave room for hash
        $maxBase = 100 - 7; // 6 chars + 1 hyphen
        if (strlen($slug) > $maxBase) {
            $slug = substr($slug, 0, $maxBase);
            $slug = rtrim($slug, '-');
        }

        $hash = substr(md5($text . microtime(true)), 0, 6);
        return $slug . '-' . $hash;
    }
}
