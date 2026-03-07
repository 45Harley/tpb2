<?php
/**
 * Opinion — helper class for public opinion tracking.
 *
 * All methods are static. Provides submit, read, and aggregate
 * operations for the public_opinions table.
 *
 * Depends on tables: public_opinions, users
 */
class Opinion
{
    /** Valid target types */
    private const VALID_TARGETS = ['declaration', 'mandate', 'issue', 'bill', 'executive_order'];

    /** Valid stances */
    private const VALID_STANCES = ['agree', 'disagree', 'mixed'];

    /* ------------------------------------------------------------------ */
    /*  1. submit                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Submit or update an opinion (identity_level >= 2 required).
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to allow changing stance.
     *
     * @param PDO         $pdo
     * @param int         $userId
     * @param string      $targetType   declaration|mandate|issue|bill|executive_order
     * @param int         $targetId
     * @param string      $stance       agree|disagree|mixed
     * @param string|null $comment
     * @param string|null $scopeType    federal|state|town
     * @param string|null $scopeId
     * @return array ['success'=>true, 'action'=>'created'|'updated'] or ['error'=>string]
     */
    public static function submit(
        PDO $pdo,
        int $userId,
        string $targetType,
        int $targetId,
        string $stance,
        ?string $comment = null,
        ?string $scopeType = null,
        ?string $scopeId = null
    ): array {
        // Validate target_type
        if (!in_array($targetType, self::VALID_TARGETS, true)) {
            return ['error' => "Invalid target_type: $targetType"];
        }

        // Validate stance
        if (!in_array($stance, self::VALID_STANCES, true)) {
            return ['error' => "Invalid stance: $stance"];
        }

        // Check identity level
        $stmt = $pdo->prepare(
            "SELECT identity_level_id FROM users WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $level = $stmt->fetchColumn();

        if ($level === false) {
            return ['error' => 'User not found.'];
        }
        if ((int) $level < 2) {
            return ['error' => 'Email verification required to submit opinions. (identity_level >= 2)'];
        }

        // Check if opinion already exists
        $existing = $pdo->prepare(
            "SELECT opinion_id FROM public_opinions
             WHERE user_id = :uid AND target_type = :tt AND target_id = :tid
             LIMIT 1"
        );
        $existing->execute([':uid' => $userId, ':tt' => $targetType, ':tid' => $targetId]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

        // Sanitize comment
        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        $sql = "INSERT INTO public_opinions
                (user_id, target_type, target_id, stance, comment, scope_type, scope_id, created_at)
                VALUES
                (:uid, :tt, :tid, :stance, :comment, :scope_type, :scope_id, NOW())
                ON DUPLICATE KEY UPDATE
                    stance = VALUES(stance),
                    comment = VALUES(comment),
                    scope_type = VALUES(scope_type),
                    scope_id = VALUES(scope_id)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid'        => $userId,
            ':tt'         => $targetType,
            ':tid'        => $targetId,
            ':stance'     => $stance,
            ':comment'    => $comment,
            ':scope_type' => $scopeType,
            ':scope_id'   => $scopeId,
        ]);

        $action = $existingRow ? 'updated' : 'created';

        return ['success' => true, 'action' => $action];
    }

    /* ------------------------------------------------------------------ */
    /*  2. getUserOpinion                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Get a user's opinion on a specific target.
     *
     * @return array|null  Opinion row or null
     */
    public static function getUserOpinion(PDO $pdo, int $userId, string $targetType, int $targetId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM public_opinions
             WHERE user_id = :uid AND target_type = :tt AND target_id = :tid
             LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':tt' => $targetType, ':tid' => $targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /* ------------------------------------------------------------------ */
    /*  3. getSentiment                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Get aggregate sentiment for a target (counts of agree/disagree/mixed).
     *
     * @return array ['agree'=>N, 'disagree'=>N, 'mixed'=>N, 'total'=>N]
     */
    public static function getSentiment(PDO $pdo, string $targetType, int $targetId): array
    {
        $stmt = $pdo->prepare(
            "SELECT stance, COUNT(*) AS cnt
             FROM public_opinions
             WHERE target_type = :tt AND target_id = :tid
             GROUP BY stance"
        );
        $stmt->execute([':tt' => $targetType, ':tid' => $targetId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $agree    = (int) ($rows['agree']    ?? 0);
        $disagree = (int) ($rows['disagree'] ?? 0);
        $mixed    = (int) ($rows['mixed']    ?? 0);

        return [
            'agree'    => $agree,
            'disagree' => $disagree,
            'mixed'    => $mixed,
            'total'    => $agree + $disagree + $mixed,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  4. getBatchSentiment                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Get sentiment for multiple targets at once (batch).
     *
     * @param PDO    $pdo
     * @param string $targetType
     * @param array  $targetIds   Array of ints
     * @return array  Keyed by target_id => ['agree'=>N, 'disagree'=>N, 'mixed'=>N, 'total'=>N]
     */
    public static function getBatchSentiment(PDO $pdo, string $targetType, array $targetIds): array
    {
        if (empty($targetIds)) {
            return [];
        }

        // Build placeholder list
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $params = array_merge([$targetType], array_map('intval', $targetIds));

        $stmt = $pdo->prepare(
            "SELECT target_id, stance, COUNT(*) AS cnt
             FROM public_opinions
             WHERE target_type = ? AND target_id IN ($placeholders)
             GROUP BY target_id, stance"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialize all requested IDs
        $result = [];
        foreach ($targetIds as $id) {
            $result[(int) $id] = ['agree' => 0, 'disagree' => 0, 'mixed' => 0, 'total' => 0];
        }

        // Fill in counts
        foreach ($rows as $row) {
            $tid = (int) $row['target_id'];
            $stance = $row['stance'];
            $cnt = (int) $row['cnt'];
            $result[$tid][$stance] = $cnt;
            $result[$tid]['total'] += $cnt;
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  5. getComments                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Get opinions with comments for a target (paginated).
     *
     * @return array  Array of opinion rows with user display_name
     */
    public static function getComments(PDO $pdo, string $targetType, int $targetId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $pdo->prepare(
            "SELECT po.*, u.display_name
             FROM public_opinions po
             LEFT JOIN users u ON u.user_id = po.user_id
             WHERE po.target_type = :tt AND po.target_id = :tid
               AND po.comment IS NOT NULL AND po.comment != ''
             ORDER BY po.created_at DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':tt', $targetType, PDO::PARAM_STR);
        $stmt->bindValue(':tid', $targetId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ------------------------------------------------------------------ */
    /*  6. getUserOpinions                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Get all opinions by a user.
     *
     * @return array  Array of opinion rows
     */
    public static function getUserOpinions(PDO $pdo, int $userId, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM public_opinions
             WHERE user_id = :uid
             ORDER BY created_at DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
