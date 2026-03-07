<?php
/**
 * Facilitator — helper class for group deliberation tools.
 *
 * Provides facilitator-only actions: surfacing options, calling votes,
 * managing rounds, merging options, and drafting/ratifying declarations.
 *
 * Depends on: Ballot class, tables: idea_groups, idea_group_members,
 *             idea_log, polls, poll_options, declarations
 */

require_once __DIR__ . '/ballot.php';

class Facilitator
{
    /* ------------------------------------------------------------------ */
    /*  Auth check                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Check if user is a facilitator of a group.
     */
    public static function isFacilitator(PDO $pdo, int $groupId, int $userId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM idea_group_members
             WHERE group_id = :gid AND user_id = :uid AND role = 'facilitator'
             LIMIT 1"
        );
        $stmt->execute([':gid' => $groupId, ':uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------------------ */
    /*  Scope derivation                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Derive scope_type and scope_id from an idea_groups row.
     *
     * idea_groups has: scope enum('town','state','federal'), state_id, town_id
     * We convert to: scope_type enum('federal','state','town'), scope_id varchar
     *
     * @return array ['scope_type'=>string, 'scope_id'=>string|null]
     */
    private static function deriveScope(PDO $pdo, array $group): array
    {
        $scope = $group['scope'] ?? 'federal';

        switch ($scope) {
            case 'federal':
                return ['scope_type' => 'federal', 'scope_id' => null];

            case 'state':
                $scopeId = null;
                if (!empty($group['state_id'])) {
                    $stmt = $pdo->prepare("SELECT abbr FROM states WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => (int) $group['state_id']]);
                    $abbr = $stmt->fetchColumn();
                    if ($abbr) $scopeId = $abbr;
                }
                return ['scope_type' => 'state', 'scope_id' => $scopeId];

            case 'town':
                $scopeId = null;
                if (!empty($group['state_id']) && !empty($group['town_id'])) {
                    // Get state abbr
                    $stmt = $pdo->prepare("SELECT abbr FROM states WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => (int) $group['state_id']]);
                    $abbr = $stmt->fetchColumn();
                    // Get town slug
                    $stmt2 = $pdo->prepare("SELECT slug FROM towns WHERE id = :id LIMIT 1");
                    $stmt2->execute([':id' => (int) $group['town_id']]);
                    $slug = $stmt2->fetchColumn();
                    if ($abbr && $slug) {
                        $scopeId = strtolower($abbr) . '-' . $slug;
                    }
                }
                return ['scope_type' => 'town', 'scope_id' => $scopeId];

            default:
                return ['scope_type' => 'federal', 'scope_id' => null];
        }
    }

    /**
     * Get a group row by ID.
     */
    private static function getGroup(PDO $pdo, int $groupId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /* ------------------------------------------------------------------ */
    /*  Surface option                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Surface an idea_log entry as a poll option on an existing ballot.
     *
     * @param PDO $pdo
     * @param int $groupId   The group
     * @param int $ideaId    The idea_log.id to surface
     * @param int $pollId    The poll to add the option to
     * @return array ['success'=>true, 'option_id'=>int] or ['error'=>string]
     */
    public static function surfaceOption(PDO $pdo, int $groupId, int $ideaId, int $pollId): array
    {
        // Verify idea belongs to this group
        $stmt = $pdo->prepare(
            "SELECT id, body FROM idea_log WHERE id = :id AND group_id = :gid LIMIT 1"
        );
        $stmt->execute([':id' => $ideaId, ':gid' => $groupId]);
        $idea = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$idea) {
            return ['error' => 'Idea not found in this group.'];
        }

        // Verify poll exists and belongs to this group (source_type='group', source_id=group_id)
        $poll = Ballot::get($pdo, $pollId);
        if (!$poll) {
            return ['error' => 'Poll not found.'];
        }
        if ($poll['source_type'] !== 'group' || (int) $poll['source_id'] !== $groupId) {
            return ['error' => 'Poll does not belong to this group.'];
        }

        // Get next option_order
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(option_order), 0) + 1 FROM poll_options WHERE poll_id = :pid"
        );
        $stmt->execute([':pid' => $pollId]);
        $nextOrder = (int) $stmt->fetchColumn();

        // Insert the option
        $stmt = $pdo->prepare(
            "INSERT INTO poll_options (poll_id, option_text, option_order, created_at)
             VALUES (:pid, :text, :ord, NOW())"
        );
        $stmt->execute([
            ':pid'  => $pollId,
            ':text' => $idea['body'],
            ':ord'  => $nextOrder,
        ]);

        return ['success' => true, 'option_id' => (int) $pdo->lastInsertId()];
    }

    /* ------------------------------------------------------------------ */
    /*  Call vote                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Create a ballot (poll) for a group.
     *
     * @param PDO   $pdo
     * @param int   $groupId
     * @param array $data  Keys: question, vote_type, threshold_type, options (array of strings), userId
     * @return array ['success'=>true, 'poll_id'=>int, 'options'=>array] or ['error'=>string]
     */
    public static function callVote(PDO $pdo, int $groupId, array $data): array
    {
        $userId = $data['userId'] ?? null;
        if (!$userId) {
            return ['error' => 'userId is required.'];
        }

        // Verify facilitator
        if (!self::isFacilitator($pdo, $groupId, (int) $userId)) {
            return ['error' => 'Only facilitators can call votes.'];
        }

        // Get group and derive scope
        $group = self::getGroup($pdo, $groupId);
        if (!$group) {
            return ['error' => 'Group not found.'];
        }

        $scope = self::deriveScope($pdo, $group);

        // Build ballot data
        $ballotData = [
            'question'       => $data['question'] ?? '',
            'scope_type'     => $scope['scope_type'],
            'scope_id'       => $scope['scope_id'],
            'vote_type'      => $data['vote_type'] ?? 'yes_no',
            'threshold_type' => $data['threshold_type'] ?? 'majority',
            'source_type'    => 'group',
            'source_id'      => $groupId,
            'created_by'     => (int) $userId,
            'options'        => $data['options'] ?? [],
        ];

        $result = Ballot::create($pdo, $ballotData);

        if (isset($result['error'])) {
            return $result;
        }

        return [
            'success' => true,
            'poll_id' => $result['poll_id'],
            'options' => $result['options'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Get active ballot                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Get the most recent active ballot for a group.
     *
     * @return array|null  Poll row with options, or null
     */
    public static function getActiveBallot(PDO $pdo, int $groupId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT poll_id FROM polls
             WHERE source_type = 'group' AND source_id = :gid
               AND active = 1 AND closed_at IS NULL
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([':gid' => $groupId]);
        $pollId = $stmt->fetchColumn();

        if (!$pollId) {
            return null;
        }

        return Ballot::get($pdo, (int) $pollId);
    }

    /* ------------------------------------------------------------------ */
    /*  Get group ballots                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Get all ballots for a group (including closed rounds).
     *
     * @return array  Array of poll rows
     */
    public static function getGroupBallots(PDO $pdo, int $groupId): array
    {
        $stmt = $pdo->prepare(
            "SELECT p.*, COUNT(DISTINCT pv.user_id) AS total_votes
             FROM polls p
             LEFT JOIN poll_votes pv ON pv.poll_id = p.poll_id
             WHERE p.source_type = 'group' AND p.source_id = :gid
             GROUP BY p.poll_id
             ORDER BY p.created_at DESC"
        );
        $stmt->execute([':gid' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ------------------------------------------------------------------ */
    /*  New round                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Start a new round from a previous ballot.
     * Closes the previous poll and creates a new one with the same options,
     * incrementing the round number.
     *
     * @param PDO $pdo
     * @param int $previousPollId
     * @param int $userId
     * @return array ['success'=>true, 'poll_id'=>int, 'options'=>array] or ['error'=>string]
     */
    public static function newRound(PDO $pdo, int $previousPollId, int $userId): array
    {
        $prevPoll = Ballot::get($pdo, $previousPollId);
        if (!$prevPoll) {
            return ['error' => 'Previous poll not found.'];
        }

        // Verify this is a group poll
        if ($prevPoll['source_type'] !== 'group' || empty($prevPoll['source_id'])) {
            return ['error' => 'Previous poll is not a group ballot.'];
        }

        $groupId = (int) $prevPoll['source_id'];

        // Verify facilitator
        if (!self::isFacilitator($pdo, $groupId, $userId)) {
            return ['error' => 'Only facilitators can start new rounds.'];
        }

        try {
            $pdo->beginTransaction();

            // Close the previous poll
            $stmt = $pdo->prepare(
                "UPDATE polls SET active = 0, closed_at = NOW(), updated_at = NOW()
                 WHERE poll_id = :pid"
            );
            $stmt->execute([':pid' => $previousPollId]);

            // Copy options text
            $optionTexts = [];
            if (!empty($prevPoll['options'])) {
                foreach ($prevPoll['options'] as $opt) {
                    $optionTexts[] = $opt['option_text'];
                }
            }

            // Create new poll with incremented round
            $newRound = ((int) ($prevPoll['round'] ?? 1)) + 1;
            $ballotData = [
                'question'       => $prevPoll['question'],
                'scope_type'     => $prevPoll['scope_type'],
                'scope_id'       => $prevPoll['scope_id'],
                'vote_type'      => $prevPoll['vote_type'],
                'threshold_type' => $prevPoll['threshold_type'],
                'quorum_type'    => $prevPoll['quorum_type'],
                'quorum_value'   => $prevPoll['quorum_value'],
                'source_type'    => 'group',
                'source_id'      => $groupId,
                'created_by'     => $userId,
                'parent_poll_id' => $previousPollId,
                'round'          => $newRound,
                'options'        => $optionTexts,
            ];

            $result = Ballot::create($pdo, $ballotData);

            if (isset($result['error'])) {
                $pdo->rollBack();
                return $result;
            }

            $pdo->commit();
            return [
                'success' => true,
                'poll_id' => $result['poll_id'],
                'round'   => $newRound,
                'options' => $result['options'],
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['error' => 'Failed to start new round: ' . $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Merge options                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Merge two options into one (for between-round refinement).
     * Keeps the first option (with updated text), marks the second as merged.
     *
     * @param PDO    $pdo
     * @param int    $keepOptionId   Option to keep
     * @param int    $mergeOptionId  Option to merge into the kept one
     * @param string $newText        New text for the merged option
     * @return array ['success'=>true] or ['error'=>string]
     */
    public static function mergeOptions(PDO $pdo, int $keepOptionId, int $mergeOptionId, string $newText): array
    {
        if ($keepOptionId === $mergeOptionId) {
            return ['error' => 'Cannot merge an option with itself.'];
        }

        if (empty(trim($newText))) {
            return ['error' => 'New text is required for merged option.'];
        }

        // Verify both options exist and belong to the same poll
        $stmt = $pdo->prepare(
            "SELECT option_id, poll_id FROM poll_options WHERE option_id IN (:a, :b)"
        );
        $stmt->execute([':a' => $keepOptionId, ':b' => $mergeOptionId]);
        $opts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($opts) !== 2) {
            return ['error' => 'One or both options not found.'];
        }

        if ((int) $opts[0]['poll_id'] !== (int) $opts[1]['poll_id']) {
            return ['error' => 'Options must belong to the same poll.'];
        }

        try {
            $pdo->beginTransaction();

            // Update the kept option's text
            $stmt = $pdo->prepare(
                "UPDATE poll_options SET option_text = :text WHERE option_id = :id"
            );
            $stmt->execute([':text' => $newText, ':id' => $keepOptionId]);

            // Mark the merged option with merged_from_option_id and remove it
            $stmt = $pdo->prepare(
                "UPDATE poll_options SET merged_from_option_id = :keep_id WHERE option_id = :merge_id"
            );
            $stmt->execute([':keep_id' => $keepOptionId, ':merge_id' => $mergeOptionId]);

            // Move any votes from merged option to kept option
            $stmt = $pdo->prepare(
                "UPDATE poll_votes SET option_id = :keep_id WHERE option_id = :merge_id"
            );
            $stmt->execute([':keep_id' => $keepOptionId, ':merge_id' => $mergeOptionId]);

            $pdo->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['error' => 'Merge failed: ' . $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Draft declaration                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Draft a declaration from a winning ballot.
     *
     * @param PDO    $pdo
     * @param int    $groupId
     * @param int    $pollId    The ballot that produced the consensus
     * @param string $title
     * @param string $body
     * @param int    $userId
     * @return array ['success'=>true, 'declaration_id'=>int] or ['error'=>string]
     */
    public static function draftDeclaration(PDO $pdo, int $groupId, int $pollId, string $title, string $body, int $userId): array
    {
        // Verify facilitator
        if (!self::isFacilitator($pdo, $groupId, $userId)) {
            return ['error' => 'Only facilitators can draft declarations.'];
        }

        // Get poll and verify it belongs to this group
        $poll = Ballot::get($pdo, $pollId);
        if (!$poll) {
            return ['error' => 'Poll not found.'];
        }
        if ($poll['source_type'] !== 'group' || (int) $poll['source_id'] !== $groupId) {
            return ['error' => 'Poll does not belong to this group.'];
        }

        // Check threshold via tally
        $tally = Ballot::tally($pdo, $pollId);
        if (isset($tally['error'])) {
            return ['error' => 'Cannot tally poll: ' . $tally['error']];
        }

        $thresholdMet = false;
        $thresholdType = $poll['threshold_type'] ?? 'majority';
        if (isset($tally['threshold_met'])) {
            $thresholdMet = $tally['threshold_met'];
        }

        if (!$thresholdMet) {
            return ['error' => 'Threshold not met on this ballot. Cannot draft declaration.'];
        }

        // Get group scope
        $group = self::getGroup($pdo, $groupId);
        if (!$group) {
            return ['error' => 'Group not found.'];
        }
        $scope = self::deriveScope($pdo, $group);

        // Determine vote counts
        $voteCount = (int) ($tally['total_votes'] ?? $tally['total_voters'] ?? 0);
        $yesCount  = (int) ($tally['yea'] ?? 0);

        // Insert declaration
        $stmt = $pdo->prepare(
            "INSERT INTO declarations
             (group_id, scope_type, scope_id, title, body, final_poll_id,
              vote_count, yes_count, threshold_met, status, created_by)
             VALUES
             (:gid, :scope_type, :scope_id, :title, :body, :poll_id,
              :vote_count, :yes_count, :threshold_met, 'draft', :user_id)"
        );
        $stmt->execute([
            ':gid'            => $groupId,
            ':scope_type'     => $scope['scope_type'],
            ':scope_id'       => $scope['scope_id'],
            ':title'          => $title,
            ':body'           => $body,
            ':poll_id'        => $pollId,
            ':vote_count'     => $voteCount,
            ':yes_count'      => $yesCount,
            ':threshold_met'  => $thresholdType,
            ':user_id'        => $userId,
        ]);

        return [
            'success'        => true,
            'declaration_id' => (int) $pdo->lastInsertId(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Ratify declaration                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Ratify a declaration after final confirmation vote passes.
     *
     * @param PDO $pdo
     * @param int $declarationId
     * @return array ['success'=>true] or ['error'=>string]
     */
    public static function ratifyDeclaration(PDO $pdo, int $declarationId): array
    {
        // Get declaration
        $stmt = $pdo->prepare(
            "SELECT * FROM declarations WHERE declaration_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $declarationId]);
        $decl = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$decl) {
            return ['error' => 'Declaration not found.'];
        }

        if ($decl['status'] === 'ratified') {
            return ['error' => 'Declaration is already ratified.'];
        }

        // If there's a final poll, update vote counts from it
        $voteCount = (int) $decl['vote_count'];
        $yesCount  = (int) $decl['yes_count'];

        if (!empty($decl['final_poll_id'])) {
            $tally = Ballot::tally($pdo, (int) $decl['final_poll_id']);
            if (!isset($tally['error'])) {
                $voteCount = (int) ($tally['total_votes'] ?? $tally['total_voters'] ?? $voteCount);
                $yesCount  = (int) ($tally['yea'] ?? $yesCount);
            }
        }

        // Update declaration
        $stmt = $pdo->prepare(
            "UPDATE declarations
             SET status = 'ratified',
                 ratified_at = NOW(),
                 vote_count = :vote_count,
                 yes_count = :yes_count
             WHERE declaration_id = :id"
        );
        $stmt->execute([
            ':vote_count' => $voteCount,
            ':yes_count'  => $yesCount,
            ':id'         => $declarationId,
        ]);

        return ['success' => true];
    }

    /* ------------------------------------------------------------------ */
    /*  List declarations                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * List declarations for a group.
     *
     * @return array  Array of declaration rows
     */
    public static function listDeclarations(PDO $pdo, int $groupId): array
    {
        $stmt = $pdo->prepare(
            "SELECT d.*, u.display_name AS author_name
             FROM declarations d
             LEFT JOIN users u ON u.user_id = d.created_by
             WHERE d.group_id = :gid
             ORDER BY d.created_at DESC"
        );
        $stmt->execute([':gid' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
