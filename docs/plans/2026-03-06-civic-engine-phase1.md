# Civic Engine Phase 1: Scope + Ballot Foundation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Extend the polls system with scope, multi-choice voting, thresholds, quorum, rounds, and a poll_options table — the foundation every later phase builds on.

**Architecture:** Additive-only DB changes (new columns with defaults, one new table). Existing polls continue working unchanged. New ballot UI sits alongside existing threat-poll UI. All work happens in the `experiment` branch.

**Tech Stack:** PHP 8.4, MySQL (PDO), vanilla JS, no framework. Dark theme, gold (#d4af37) accent.

**Branch:** `experiment` (working directory: `c:\tpb`)
**Staging:** tpb.sandgems.net (pull experiment branch)
**Design doc:** `docs/plans/civic-engine-design.md`

---

## Task 1: Schema Migration — Add scope columns to polls

**Files:**
- Create: `scripts/db/civic-engine-phase1.sql`

**Step 1: Write the migration SQL**

```sql
-- Civic Engine Phase 1: Scope + Ballot Foundation
-- Run against sandge5_tpb2 database
-- All changes are additive with safe defaults — existing polls unaffected

-- 1. Add scope columns to polls
ALTER TABLE polls ADD COLUMN scope_type ENUM('federal','state','town','group') DEFAULT 'federal';
ALTER TABLE polls ADD COLUMN scope_id VARCHAR(50) DEFAULT NULL;
ALTER TABLE polls ADD INDEX idx_polls_scope (scope_type, scope_id);
```

**Step 2: Verify existing polls table structure**

Run on server:
```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query('DESCRIBE polls');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Confirm `scope_type` and `scope_id` do NOT already exist before running migration.

**Step 3: Commit**

```bash
cd /c/tpb && git add scripts/db/civic-engine-phase1.sql
git commit -m "feat: Phase 1 migration SQL — scope columns on polls"
```

---

## Task 2: Schema Migration — Add vote_type, threshold, quorum, round, parent, source columns

**Files:**
- Modify: `scripts/db/civic-engine-phase1.sql`

**Step 1: Append ballot columns to the migration file**

Add to `civic-engine-phase1.sql`:

```sql
-- 2. Add ballot columns to polls
ALTER TABLE polls ADD COLUMN vote_type ENUM('yes_no','yes_no_novote','multi_choice','ranked_choice') DEFAULT 'yes_no';
ALTER TABLE polls ADD COLUMN threshold_type ENUM('plurality','majority','three_fifths','two_thirds','three_quarters','unanimous') DEFAULT 'majority';
ALTER TABLE polls ADD COLUMN quorum_type ENUM('percent','minimum','none') DEFAULT 'none';
ALTER TABLE polls ADD COLUMN quorum_value INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN round INT DEFAULT 1;
ALTER TABLE polls ADD COLUMN parent_poll_id INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN declaration_id INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN source_type ENUM('manual','threat','bill','executive_order','group') DEFAULT 'manual';
ALTER TABLE polls ADD COLUMN source_id INT DEFAULT NULL;

ALTER TABLE polls ADD INDEX idx_polls_source (source_type, source_id);
ALTER TABLE polls ADD INDEX idx_polls_parent (parent_poll_id);
```

**Step 2: Commit**

```bash
cd /c/tpb && git add scripts/db/civic-engine-phase1.sql
git commit -m "feat: Phase 1 migration — ballot columns (vote_type, threshold, quorum, rounds)"
```

---

## Task 3: Schema Migration — Create poll_options table

**Files:**
- Modify: `scripts/db/civic-engine-phase1.sql`

**Step 1: Append poll_options table to the migration file**

Add to `civic-engine-phase1.sql`:

```sql
-- 3. Create poll_options table (for multi-choice and ranked-choice ballots)
CREATE TABLE IF NOT EXISTS poll_options (
    option_id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_text TEXT NOT NULL,
    option_order INT DEFAULT 0,
    merged_from_option_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (poll_id) REFERENCES polls(poll_id) ON DELETE CASCADE
);

ALTER TABLE poll_options ADD INDEX idx_poll_options_poll (poll_id, option_order);
```

**Step 2: Commit**

```bash
cd /c/tpb && git add scripts/db/civic-engine-phase1.sql
git commit -m "feat: Phase 1 migration — poll_options table"
```

---

## Task 4: Schema Migration — Extend poll_votes for options and ranking

**Files:**
- Modify: `scripts/db/civic-engine-phase1.sql`

**Step 1: Append poll_votes columns to the migration file**

Add to `civic-engine-phase1.sql`:

```sql
-- 4. Extend poll_votes for multi-choice and ranked-choice
ALTER TABLE poll_votes ADD COLUMN option_id INT DEFAULT NULL;
ALTER TABLE poll_votes ADD COLUMN rank_position INT DEFAULT NULL;

ALTER TABLE poll_votes ADD INDEX idx_poll_votes_option (poll_id, option_id);
```

**Step 2: Commit**

```bash
cd /c/tpb && git add scripts/db/civic-engine-phase1.sql
git commit -m "feat: Phase 1 migration — poll_votes option_id + rank_position"
```

---

## Task 5: Schema Migration — Backfill existing polls

**Files:**
- Modify: `scripts/db/civic-engine-phase1.sql`

**Step 1: Append backfill statements**

Add to `civic-engine-phase1.sql`:

```sql
-- 5. Backfill existing polls with safe defaults
-- All existing polls are federal-scope, yes/no, majority threshold, manual or threat source
UPDATE polls SET scope_type = 'federal' WHERE scope_type IS NULL;
UPDATE polls SET vote_type = 'yes_no' WHERE vote_type IS NULL;
UPDATE polls SET threshold_type = 'majority' WHERE threshold_type IS NULL;
UPDATE polls SET quorum_type = 'none' WHERE quorum_type IS NULL;
UPDATE polls SET round = 1 WHERE round IS NULL;
UPDATE polls SET source_type = 'threat' WHERE threat_id IS NOT NULL AND source_type = 'manual';
UPDATE polls SET source_id = threat_id WHERE threat_id IS NOT NULL AND source_id IS NULL;

-- 6. Verification queries (run manually to confirm)
-- SELECT vote_type, COUNT(*) FROM polls GROUP BY vote_type;
-- SELECT source_type, COUNT(*) FROM polls GROUP BY source_type;
-- SELECT scope_type, COUNT(*) FROM polls GROUP BY scope_type;
```

**Step 2: Commit**

```bash
cd /c/tpb && git add scripts/db/civic-engine-phase1.sql
git commit -m "feat: Phase 1 migration — backfill existing polls with defaults"
```

---

## Task 6: Run migration on database

**Step 1: Run the full migration SQL on the server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$sql = file_get_contents('/home/sandge5/tpb.sandgems.net/scripts/db/civic-engine-phase1.sql');
\$statements = array_filter(array_map('trim', explode(';', \$sql)));
foreach (\$statements as \$stmt) {
    if (empty(\$stmt) || strpos(\$stmt, '--') === 0) continue;
    try {
        \$p->exec(\$stmt);
        echo 'OK: ' . substr(\$stmt, 0, 60) . PHP_EOL;
    } catch (Exception \$e) {
        echo 'ERR: ' . \$e->getMessage() . ' — ' . substr(\$stmt, 0, 60) . PHP_EOL;
    }
}
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

NOTE: Push experiment branch and pull on tpb.sandgems.net FIRST so the SQL file exists there.

**Step 2: Verify with DESCRIBE**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
echo '=== POLLS ===' . PHP_EOL;
\$r = \$p->query('DESCRIBE polls');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['Field'] . ' | ' . \$row['Type'] . ' | ' . \$row['Default'] . PHP_EOL;
echo PHP_EOL . '=== POLL_OPTIONS ===' . PHP_EOL;
\$r = \$p->query('DESCRIBE poll_options');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['Field'] . ' | ' . \$row['Type'] . ' | ' . \$row['Default'] . PHP_EOL;
echo PHP_EOL . '=== POLL_VOTES ===' . PHP_EOL;
\$r = \$p->query('DESCRIBE poll_votes');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['Field'] . ' | ' . \$row['Type'] . ' | ' . \$row['Default'] . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: polls has scope_type, scope_id, vote_type, threshold_type, quorum_type, quorum_value, round, parent_poll_id, declaration_id, source_type, source_id. poll_options table exists. poll_votes has option_id, rank_position.

---

## Task 7: Create ballot helper — includes/ballot.php

**Files:**
- Create: `includes/ballot.php`

**Step 1: Write the ballot helper**

This PHP include provides functions for creating, reading, and tallying ballots. It's the server-side engine for all ballot operations.

```php
<?php
/**
 * Ballot Helper — Civic Engine Phase 1
 * =====================================
 * Functions for creating, reading, and tallying ballots (polls with options).
 *
 * Usage:
 *   require_once __DIR__ . '/ballot.php';
 *   $ballot = Ballot::create($pdo, [...]);
 *   $tally = Ballot::tally($pdo, $pollId);
 */

class Ballot {

    /**
     * Create a new ballot (poll + options if multi-choice/ranked).
     *
     * @param PDO $pdo
     * @param array $data Keys: question, slug, scope_type, scope_id, vote_type,
     *                     threshold_type, quorum_type, quorum_value, created_by,
     *                     source_type, source_id, options (array of strings),
     *                     parent_poll_id, round
     * @return array ['poll_id' => int, 'options' => array] or ['error' => string]
     */
    public static function create(PDO $pdo, array $data): array {
        $required = ['question', 'vote_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['error' => "Missing required field: $field"];
            }
        }

        // Generate slug if not provided
        $slug = $data['slug'] ?? self::generateSlug($data['question']);

        // Multi-choice and ranked-choice require options
        $needsOptions = in_array($data['vote_type'], ['multi_choice', 'ranked_choice']);
        if ($needsOptions && (empty($data['options']) || count($data['options']) < 2)) {
            return ['error' => 'multi_choice and ranked_choice require at least 2 options'];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO polls (question, slug, scope_type, scope_id, vote_type,
                    threshold_type, quorum_type, quorum_value, created_by,
                    source_type, source_id, parent_poll_id, round, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $data['question'],
                $slug,
                $data['scope_type'] ?? 'federal',
                $data['scope_id'] ?? null,
                $data['vote_type'],
                $data['threshold_type'] ?? 'majority',
                $data['quorum_type'] ?? 'none',
                $data['quorum_value'] ?? null,
                $data['created_by'] ?? null,
                $data['source_type'] ?? 'manual',
                $data['source_id'] ?? null,
                $data['parent_poll_id'] ?? null,
                $data['round'] ?? 1,
            ]);
            $pollId = (int)$pdo->lastInsertId();

            $options = [];
            if ($needsOptions && !empty($data['options'])) {
                $optStmt = $pdo->prepare("
                    INSERT INTO poll_options (poll_id, option_text, option_order)
                    VALUES (?, ?, ?)
                ");
                foreach ($data['options'] as $i => $text) {
                    $optStmt->execute([$pollId, trim($text), $i]);
                    $options[] = [
                        'option_id' => (int)$pdo->lastInsertId(),
                        'option_text' => trim($text),
                        'option_order' => $i,
                    ];
                }
            }

            $pdo->commit();
            return ['poll_id' => $pollId, 'options' => $options];

        } catch (PDOException $e) {
            $pdo->rollBack();
            return ['error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Get a ballot with its options.
     */
    public static function get(PDO $pdo, int $pollId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM polls WHERE poll_id = ?");
        $stmt->execute([$pollId]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$poll) return null;

        $poll['options'] = [];
        if (in_array($poll['vote_type'], ['multi_choice', 'ranked_choice'])) {
            $optStmt = $pdo->prepare("
                SELECT * FROM poll_options WHERE poll_id = ? ORDER BY option_order
            ");
            $optStmt->execute([$pollId]);
            $poll['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $poll;
    }

    /**
     * Cast a vote. Handles yes/no, multi-choice, and ranked-choice.
     *
     * @param PDO $pdo
     * @param int $pollId
     * @param int $userId
     * @param array $voteData For yes_no: ['vote_choice' => 'yea'|'nay'|'abstain']
     *                        For multi_choice: ['option_id' => int]
     *                        For ranked_choice: ['rankings' => [option_id => rank_position, ...]]
     * @param bool $isRep
     * @return array ['success' => bool, 'action' => 'created'|'updated', ...] or ['error' => string]
     */
    public static function vote(PDO $pdo, int $pollId, int $userId, array $voteData, bool $isRep = false): array {
        $poll = self::get($pdo, $pollId);
        if (!$poll) return ['error' => 'Poll not found'];
        if (!$poll['active']) return ['error' => 'Poll is closed'];

        try {
            $pdo->beginTransaction();

            switch ($poll['vote_type']) {
                case 'yes_no':
                case 'yes_no_novote':
                    $choice = $voteData['vote_choice'] ?? '';
                    $valid = ($poll['vote_type'] === 'yes_no')
                        ? ['yea', 'nay', 'abstain']
                        : ['yea', 'nay', 'novote', 'abstain'];
                    if (!in_array($choice, $valid)) {
                        $pdo->rollBack();
                        return ['error' => "Invalid vote choice: $choice"];
                    }

                    $existing = $pdo->prepare("SELECT poll_vote_id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                    $existing->execute([$pollId, $userId]);
                    $row = $existing->fetch();

                    if ($row) {
                        $upd = $pdo->prepare("UPDATE poll_votes SET vote_choice = ?, is_rep_vote = ?, updated_at = NOW() WHERE poll_vote_id = ?");
                        $upd->execute([$choice, $isRep ? 1 : 0, $row['poll_vote_id']]);
                        $pdo->commit();
                        return ['success' => true, 'action' => 'updated'];
                    } else {
                        $ins = $pdo->prepare("INSERT INTO poll_votes (poll_id, user_id, vote_choice, is_rep_vote) VALUES (?, ?, ?, ?)");
                        $ins->execute([$pollId, $userId, $choice, $isRep ? 1 : 0]);
                        $pdo->commit();
                        return ['success' => true, 'action' => 'created'];
                    }

                case 'multi_choice':
                    $optionId = $voteData['option_id'] ?? null;
                    if (!$optionId) {
                        $pdo->rollBack();
                        return ['error' => 'option_id required for multi_choice'];
                    }

                    // Verify option belongs to this poll
                    $optCheck = $pdo->prepare("SELECT option_id FROM poll_options WHERE option_id = ? AND poll_id = ?");
                    $optCheck->execute([$optionId, $pollId]);
                    if (!$optCheck->fetch()) {
                        $pdo->rollBack();
                        return ['error' => 'Invalid option for this poll'];
                    }

                    $existing = $pdo->prepare("SELECT poll_vote_id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                    $existing->execute([$pollId, $userId]);
                    $row = $existing->fetch();

                    if ($row) {
                        $upd = $pdo->prepare("UPDATE poll_votes SET option_id = ?, vote_choice = 'yea', is_rep_vote = ?, updated_at = NOW() WHERE poll_vote_id = ?");
                        $upd->execute([$optionId, $isRep ? 1 : 0, $row['poll_vote_id']]);
                        $pdo->commit();
                        return ['success' => true, 'action' => 'updated'];
                    } else {
                        $ins = $pdo->prepare("INSERT INTO poll_votes (poll_id, user_id, option_id, vote_choice, is_rep_vote) VALUES (?, ?, ?, 'yea', ?)");
                        $ins->execute([$pollId, $userId, $optionId, $isRep ? 1 : 0]);
                        $pdo->commit();
                        return ['success' => true, 'action' => 'created'];
                    }

                case 'ranked_choice':
                    $rankings = $voteData['rankings'] ?? [];
                    if (empty($rankings)) {
                        $pdo->rollBack();
                        return ['error' => 'rankings required for ranked_choice'];
                    }

                    // Delete existing rankings for this user on this poll
                    $del = $pdo->prepare("DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                    $del->execute([$pollId, $userId]);

                    $ins = $pdo->prepare("INSERT INTO poll_votes (poll_id, user_id, option_id, rank_position, vote_choice, is_rep_vote) VALUES (?, ?, ?, ?, 'yea', ?)");
                    foreach ($rankings as $optionId => $rank) {
                        $ins->execute([$pollId, $userId, (int)$optionId, (int)$rank, $isRep ? 1 : 0]);
                    }

                    $pdo->commit();
                    $action = count($rankings) > 0 ? 'created' : 'updated';
                    return ['success' => true, 'action' => $action, 'rankings_count' => count($rankings)];

                default:
                    $pdo->rollBack();
                    return ['error' => "Unknown vote_type: {$poll['vote_type']}"];
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            return ['error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Tally votes for a ballot. Returns results appropriate to vote_type.
     */
    public static function tally(PDO $pdo, int $pollId): array {
        $poll = self::get($pdo, $pollId);
        if (!$poll) return ['error' => 'Poll not found'];

        $result = [
            'poll_id' => $pollId,
            'vote_type' => $poll['vote_type'],
            'threshold_type' => $poll['threshold_type'],
            'quorum_type' => $poll['quorum_type'],
            'quorum_value' => $poll['quorum_value'],
            'round' => $poll['round'],
        ];

        switch ($poll['vote_type']) {
            case 'yes_no':
            case 'yes_no_novote':
                $stmt = $pdo->prepare("
                    SELECT
                        COUNT(*) as total_votes,
                        SUM(CASE WHEN vote_choice = 'yea' THEN 1 ELSE 0 END) as yea,
                        SUM(CASE WHEN vote_choice = 'nay' THEN 1 ELSE 0 END) as nay,
                        SUM(CASE WHEN vote_choice = 'abstain' THEN 1 ELSE 0 END) as abstain,
                        SUM(CASE WHEN vote_choice = 'novote' THEN 1 ELSE 0 END) as novote
                    FROM poll_votes WHERE poll_id = ?
                ");
                $stmt->execute([$pollId]);
                $counts = $stmt->fetch(PDO::FETCH_ASSOC);
                $result = array_merge($result, $counts);
                $result['threshold_met'] = self::checkThreshold(
                    (int)$counts['yea'], (int)$counts['nay'], (int)$counts['total_votes'],
                    $poll['threshold_type']
                );
                break;

            case 'multi_choice':
                $stmt = $pdo->prepare("
                    SELECT po.option_id, po.option_text, po.option_order,
                           COUNT(pv.poll_vote_id) as vote_count
                    FROM poll_options po
                    LEFT JOIN poll_votes pv ON po.option_id = pv.option_id AND pv.poll_id = ?
                    WHERE po.poll_id = ?
                    GROUP BY po.option_id
                    ORDER BY vote_count DESC, po.option_order
                ");
                $stmt->execute([$pollId, $pollId]);
                $result['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $totalStmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) as total FROM poll_votes WHERE poll_id = ?");
                $totalStmt->execute([$pollId]);
                $result['total_voters'] = (int)$totalStmt->fetchColumn();

                // Winner = most votes
                if (!empty($result['options'])) {
                    $top = $result['options'][0];
                    $result['winner'] = $top;
                    $result['threshold_met'] = self::checkThreshold(
                        (int)$top['vote_count'], $result['total_voters'] - (int)$top['vote_count'],
                        $result['total_voters'], $poll['threshold_type']
                    );
                }
                break;

            case 'ranked_choice':
                // Instant-runoff tallying
                $result = array_merge($result, self::tallyRankedChoice($pdo, $pollId, $poll));
                break;
        }

        // Check quorum
        $totalVoters = $result['total_voters'] ?? ($result['total_votes'] ?? 0);
        $result['quorum_met'] = self::checkQuorum(
            $totalVoters, $poll['quorum_type'], $poll['quorum_value']
        );

        return $result;
    }

    /**
     * Instant-runoff tallying for ranked-choice.
     */
    private static function tallyRankedChoice(PDO $pdo, int $pollId, array $poll): array {
        // Get all rankings
        $stmt = $pdo->prepare("
            SELECT user_id, option_id, rank_position
            FROM poll_votes
            WHERE poll_id = ?
            ORDER BY user_id, rank_position
        ");
        $stmt->execute([$pollId]);
        $allVotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by user
        $ballots = [];
        foreach ($allVotes as $v) {
            $ballots[$v['user_id']][$v['rank_position']] = $v['option_id'];
        }
        // Sort each ballot by rank
        foreach ($ballots as &$b) ksort($b);
        unset($b);

        $totalVoters = count($ballots);
        $eliminated = [];
        $rounds = [];

        // Get all option IDs
        $optStmt = $pdo->prepare("SELECT option_id, option_text FROM poll_options WHERE poll_id = ?");
        $optStmt->execute([$pollId]);
        $optionNames = $optStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $maxRounds = count($optionNames);
        for ($r = 1; $r <= $maxRounds; $r++) {
            // Count first-choice votes (excluding eliminated)
            $counts = [];
            foreach ($optionNames as $oid => $name) {
                if (!in_array($oid, $eliminated)) $counts[$oid] = 0;
            }
            foreach ($ballots as $ballot) {
                foreach ($ballot as $optId) {
                    if (isset($counts[$optId])) {
                        $counts[$optId]++;
                        break; // Only count top remaining choice
                    }
                }
            }

            $roundData = [];
            foreach ($counts as $oid => $cnt) {
                $roundData[] = ['option_id' => $oid, 'option_text' => $optionNames[$oid], 'votes' => $cnt];
            }
            usort($roundData, fn($a, $b) => $b['votes'] - $a['votes']);
            $rounds[] = ['round' => $r, 'results' => $roundData];

            // Check if winner meets threshold
            if (!empty($roundData)) {
                $topVotes = $roundData[0]['votes'];
                $otherVotes = $totalVoters - $topVotes;
                if (self::checkThreshold($topVotes, $otherVotes, $totalVoters, $poll['threshold_type'])) {
                    return [
                        'total_voters' => $totalVoters,
                        'rounds' => $rounds,
                        'winner' => $roundData[0],
                        'threshold_met' => true,
                        'decided_in_round' => $r,
                    ];
                }
            }

            // Eliminate lowest
            if (count($counts) <= 2) break; // Can't eliminate further
            $minVotes = min($counts);
            $lowest = array_keys(array_filter($counts, fn($c) => $c === $minVotes));
            foreach ($lowest as $oid) $eliminated[] = $oid;
        }

        return [
            'total_voters' => $totalVoters,
            'rounds' => $rounds,
            'winner' => $rounds[count($rounds) - 1]['results'][0] ?? null,
            'threshold_met' => false,
            'decided_in_round' => null,
        ];
    }

    /**
     * Check if a threshold is met.
     */
    public static function checkThreshold(int $yea, int $nay, int $total, string $type): bool {
        if ($total === 0) return false;
        $ratio = $yea / $total;
        return match($type) {
            'plurality' => $yea > $nay,
            'majority' => $ratio > 0.5,
            'three_fifths' => $ratio >= 0.6,
            'two_thirds' => $ratio >= (2/3),
            'three_quarters' => $ratio >= 0.75,
            'unanimous' => $yea === $total && $total > 0,
            default => false,
        };
    }

    /**
     * Check if quorum is met.
     */
    public static function checkQuorum(int $totalVoters, string $type, ?int $value): bool {
        return match($type) {
            'none' => true,
            'minimum' => $totalVoters >= ($value ?? 0),
            'percent' => true, // Needs eligible count — caller must provide context
            default => true,
        };
    }

    /**
     * Generate URL-safe slug from question text.
     */
    private static function generateSlug(string $text): string {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return substr($slug, 0, 100) . '-' . substr(md5(microtime()), 0, 6);
    }

    /**
     * List ballots filtered by scope.
     */
    public static function listByScope(PDO $pdo, string $scopeType, ?string $scopeId = null, bool $activeOnly = true): array {
        $sql = "SELECT p.*, COUNT(pv.poll_vote_id) as total_votes
                FROM polls p
                LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
                WHERE p.scope_type = ?";
        $params = [$scopeType];

        if ($scopeId !== null) {
            $sql .= " AND p.scope_id = ?";
            $params[] = $scopeId;
        }

        if ($activeOnly) {
            $sql .= " AND p.active = 1";
        }

        $sql .= " GROUP BY p.poll_id ORDER BY p.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

**Step 2: Commit**

```bash
cd /c/tpb && git add includes/ballot.php
git commit -m "feat: Ballot helper class — create, vote, tally for all vote types"
```

---

## Task 8: Create ballot API endpoint — api/ballot.php

**Files:**
- Create: `api/ballot.php`

**Step 1: Write the ballot API**

```php
<?php
/**
 * Ballot API — Civic Engine Phase 1
 * ==================================
 * JSON API for ballot operations: create, vote, tally, list.
 *
 * Endpoints (via ?action=):
 *   create  — POST: create a new ballot (facilitator/admin)
 *   vote    — POST: cast or update a vote
 *   tally   — GET:  get current vote tally
 *   get     — GET:  get ballot details with options
 *   list    — GET:  list ballots by scope
 */

header('Content-Type: application/json');

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/ballot.php';

$dbUser = getUser($pdo);
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        if (!$dbUser) {
            http_response_code(401);
            echo json_encode(['error' => 'Login required']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }

        $input['created_by'] = $dbUser['user_id'];
        $result = Ballot::create($pdo, $input);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => true] + $result);
        }
        break;

    case 'vote':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        if (!$dbUser) {
            http_response_code(401);
            echo json_encode(['error' => 'Login required']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['poll_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'poll_id required']);
            exit;
        }

        $isRep = !empty($dbUser['official_id']);
        $result = Ballot::vote($pdo, (int)$input['poll_id'], $dbUser['user_id'], $input, $isRep);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
        } else {
            // Award points on new vote
            if ($result['action'] === 'created') {
                require_once __DIR__ . '/../includes/point-logger.php';
                PointLogger::init($pdo);
                PointLogger::award($dbUser['user_id'], 'poll_voted', 'poll', (int)$input['poll_id']);
            }
            echo json_encode($result);
        }
        break;

    case 'tally':
        $pollId = (int)($_GET['poll_id'] ?? 0);
        if (!$pollId) {
            http_response_code(400);
            echo json_encode(['error' => 'poll_id required']);
            exit;
        }
        echo json_encode(Ballot::tally($pdo, $pollId));
        break;

    case 'get':
        $pollId = (int)($_GET['poll_id'] ?? 0);
        if (!$pollId) {
            http_response_code(400);
            echo json_encode(['error' => 'poll_id required']);
            exit;
        }
        $poll = Ballot::get($pdo, $pollId);
        if (!$poll) {
            http_response_code(404);
            echo json_encode(['error' => 'Poll not found']);
        } else {
            // Include user's vote if logged in
            if ($dbUser) {
                $stmt = $pdo->prepare("SELECT vote_choice, option_id, rank_position FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $stmt->execute([$pollId, $dbUser['user_id']]);
                $poll['user_vote'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'poll' => $poll]);
        }
        break;

    case 'list':
        $scopeType = $_GET['scope_type'] ?? 'federal';
        $scopeId = $_GET['scope_id'] ?? null;
        $activeOnly = ($_GET['active'] ?? '1') === '1';
        $polls = Ballot::listByScope($pdo, $scopeType, $scopeId, $activeOnly);
        echo json_encode(['success' => true, 'polls' => $polls]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Valid: create, vote, tally, get, list']);
}
```

**Step 2: Commit**

```bash
cd /c/tpb && git add api/ballot.php
git commit -m "feat: Ballot API endpoint — create, vote, tally, get, list"
```

---

## Task 9: Create ballot UI component — includes/ballot-card.php

**Files:**
- Create: `includes/ballot-card.php`

**Step 1: Write the reusable ballot card component**

This PHP include renders a single ballot card with vote buttons appropriate to the vote type. It's designed to be embedded in any page (polls, groups, town dashboard).

```php
<?php
/**
 * Ballot Card Component — Civic Engine Phase 1
 * ==============================================
 * Renders a single ballot card with vote buttons.
 *
 * Required variables:
 *   $ballot     — array from Ballot::get() (poll row + options)
 *   $tally      — array from Ballot::tally()
 *   $userVote   — array of user's vote(s) or empty array
 *   $canVote    — bool (user is logged in and meets identity level)
 *
 * Optional:
 *   $ballotPrefix — string for unique DOM IDs (default: 'b')
 */

$ballotPrefix = $ballotPrefix ?? 'b';
$bid = $ballotPrefix . $ballot['poll_id'];
$voteType = $ballot['vote_type'] ?? 'yes_no';
$thresholdLabel = str_replace('_', ' ', ucfirst($ballot['threshold_type'] ?? 'majority'));

// Current user's vote
$currentChoice = '';
$currentOptionId = null;
$currentRankings = [];
foreach ($userVote as $v) {
    if ($voteType === 'ranked_choice') {
        $currentRankings[$v['option_id']] = $v['rank_position'];
    } else {
        $currentChoice = $v['vote_choice'] ?? '';
        $currentOptionId = $v['option_id'] ?? null;
    }
}
?>

<div class="ballot-card" id="<?= $bid ?>" data-poll-id="<?= $ballot['poll_id'] ?>" data-vote-type="<?= $voteType ?>">
    <div class="ballot-header">
        <div class="ballot-question"><?= htmlspecialchars($ballot['question']) ?></div>
        <div class="ballot-meta">
            <span class="ballot-type-badge"><?= str_replace('_', ' ', ucfirst($voteType)) ?></span>
            <?php if ($ballot['round'] > 1): ?>
                <span class="ballot-round-badge">Round <?= $ballot['round'] ?></span>
            <?php endif; ?>
            <span class="ballot-threshold"><?= $thresholdLabel ?> required</span>
        </div>
    </div>

    <div class="ballot-body">
        <?php if ($voteType === 'yes_no' || $voteType === 'yes_no_novote'): ?>
            <div class="ballot-yn-buttons">
                <button class="vote-btn yea <?= $currentChoice === 'yea' ? 'selected' : '' ?>"
                        onclick="ballotVote(<?= $ballot['poll_id'] ?>, {vote_choice:'yea'})"
                        <?= !$canVote ? 'disabled' : '' ?>>Yea</button>
                <button class="vote-btn nay <?= $currentChoice === 'nay' ? 'selected' : '' ?>"
                        onclick="ballotVote(<?= $ballot['poll_id'] ?>, {vote_choice:'nay'})"
                        <?= !$canVote ? 'disabled' : '' ?>>Nay</button>
                <button class="vote-btn abstain-btn <?= $currentChoice === 'abstain' ? 'selected' : '' ?>"
                        onclick="ballotVote(<?= $ballot['poll_id'] ?>, {vote_choice:'abstain'})"
                        <?= !$canVote ? 'disabled' : '' ?>>Abstain</button>
                <?php if ($voteType === 'yes_no_novote'): ?>
                    <button class="vote-btn novote-btn <?= $currentChoice === 'novote' ? 'selected' : '' ?>"
                            onclick="ballotVote(<?= $ballot['poll_id'] ?>, {vote_choice:'novote'})"
                            <?= !$canVote ? 'disabled' : '' ?>>No Vote</button>
                <?php endif; ?>
            </div>

            <?php if (!empty($tally)): ?>
            <div class="ballot-tally">
                <?php
                $total = max(1, (int)($tally['total_votes'] ?? 0));
                $yea = (int)($tally['yea'] ?? 0);
                $nay = (int)($tally['nay'] ?? 0);
                $yeaPct = round($yea / $total * 100);
                $nayPct = round($nay / $total * 100);
                ?>
                <div class="tally-bar">
                    <div class="tally-yea-bar" style="width: <?= $yeaPct ?>%"><?= $yeaPct > 5 ? $yeaPct . '%' : '' ?></div>
                    <div class="tally-nay-bar" style="width: <?= $nayPct ?>%"><?= $nayPct > 5 ? $nayPct . '%' : '' ?></div>
                </div>
                <div class="tally-counts">
                    <span class="tally-yea"><?= $yea ?> Yea</span>
                    <span class="tally-nay"><?= $nay ?> Nay</span>
                    <span class="tally-total"><?= $total ?> total</span>
                    <?php if (!empty($tally['threshold_met'])): ?>
                        <span class="tally-passed">Threshold met</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($voteType === 'multi_choice'): ?>
            <div class="ballot-options">
                <?php foreach ($ballot['options'] as $opt): ?>
                    <?php $isSelected = ($currentOptionId == $opt['option_id']); ?>
                    <button class="ballot-option <?= $isSelected ? 'selected' : '' ?>"
                            onclick="ballotVote(<?= $ballot['poll_id'] ?>, {option_id:<?= $opt['option_id'] ?>})"
                            <?= !$canVote ? 'disabled' : '' ?>>
                        <span class="option-text"><?= htmlspecialchars($opt['option_text']) ?></span>
                        <?php
                        $optTally = array_filter($tally['options'] ?? [], fn($o) => $o['option_id'] == $opt['option_id']);
                        $optCount = !empty($optTally) ? (int)array_values($optTally)[0]['vote_count'] : 0;
                        ?>
                        <span class="option-count"><?= $optCount ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

        <?php elseif ($voteType === 'ranked_choice'): ?>
            <div class="ballot-ranked" id="<?= $bid ?>-ranked">
                <p class="ballot-ranked-hint">Drag to rank your preferences (1 = top choice):</p>
                <div class="ballot-ranked-list" id="<?= $bid ?>-ranked-list">
                    <?php
                    $options = $ballot['options'];
                    // Sort by user's existing ranking if any
                    if (!empty($currentRankings)) {
                        usort($options, function($a, $b) use ($currentRankings) {
                            $ra = $currentRankings[$a['option_id']] ?? 999;
                            $rb = $currentRankings[$b['option_id']] ?? 999;
                            return $ra - $rb;
                        });
                    }
                    foreach ($options as $i => $opt):
                    ?>
                        <div class="ranked-item" data-option-id="<?= $opt['option_id'] ?>" draggable="true">
                            <span class="ranked-num"><?= $i + 1 ?></span>
                            <span class="ranked-text"><?= htmlspecialchars($opt['option_text']) ?></span>
                            <span class="ranked-handle">&#x2630;</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn ballot-submit-ranked"
                        onclick="ballotSubmitRanked(<?= $ballot['poll_id'] ?>, '<?= $bid ?>')"
                        <?= !$canVote ? 'disabled' : '' ?>>Submit Rankings</button>
            </div>

            <?php if (!empty($tally['rounds'])): ?>
            <div class="ballot-rcv-results">
                <p>Decided in round <?= $tally['decided_in_round'] ?? count($tally['rounds']) ?></p>
                <?php if (!empty($tally['winner'])): ?>
                    <p class="ballot-winner">Winner: <?= htmlspecialchars($tally['winner']['option_text']) ?> (<?= $tally['winner']['votes'] ?> votes)</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!$canVote): ?>
        <div class="ballot-login-prompt">
            <a href="/login.php">Log in</a> to vote
        </div>
    <?php endif; ?>
</div>
```

**Step 2: Commit**

```bash
cd /c/tpb && git add includes/ballot-card.php
git commit -m "feat: Ballot card component — yes/no, multi-choice, ranked-choice UI"
```

---

## Task 10: Create ballot CSS + JS — assets/ballot.css, assets/ballot.js

**Files:**
- Create: `assets/ballot.css`
- Create: `assets/ballot.js`

**Step 1: Write ballot CSS**

```css
/* Ballot Card — Civic Engine Phase 1 */

.ballot-card {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
}

.ballot-header {
    margin-bottom: 15px;
}

.ballot-question {
    font-size: 1.1rem;
    font-weight: 600;
    color: #e0e0e0;
    margin-bottom: 8px;
}

.ballot-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.ballot-type-badge {
    background: rgba(212, 175, 55, 0.15);
    color: #d4af37;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.ballot-round-badge {
    background: rgba(79, 195, 247, 0.15);
    color: #4fc3f7;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.ballot-threshold {
    color: #888;
    font-size: 0.8rem;
}

/* Yes/No buttons */
.ballot-yn-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.ballot-card .vote-btn {
    flex: 1;
    padding: 10px;
    border: 2px solid;
    border-radius: 6px;
    background: transparent;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.15s;
}

.ballot-card .vote-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ballot-card .vote-btn.yea { border-color: #4caf50; color: #4caf50; }
.ballot-card .vote-btn.yea:hover:not(:disabled) { background: rgba(76, 175, 80, 0.15); }
.ballot-card .vote-btn.yea.selected { background: #4caf50; color: #fff; }

.ballot-card .vote-btn.nay { border-color: #f44336; color: #f44336; }
.ballot-card .vote-btn.nay:hover:not(:disabled) { background: rgba(244, 67, 54, 0.15); }
.ballot-card .vote-btn.nay.selected { background: #f44336; color: #fff; }

.ballot-card .vote-btn.abstain-btn { border-color: #888; color: #888; }
.ballot-card .vote-btn.abstain-btn:hover:not(:disabled) { background: rgba(136, 136, 136, 0.15); }
.ballot-card .vote-btn.abstain-btn.selected { background: #888; color: #fff; }

.ballot-card .vote-btn.novote-btn { border-color: #666; color: #666; }
.ballot-card .vote-btn.novote-btn:hover:not(:disabled) { background: rgba(102, 102, 102, 0.15); }
.ballot-card .vote-btn.novote-btn.selected { background: #666; color: #fff; }

/* Tally bar */
.ballot-tally {
    margin-top: 10px;
}

.tally-bar {
    display: flex;
    height: 24px;
    border-radius: 12px;
    overflow: hidden;
    background: #2a2a3e;
}

.tally-yea-bar {
    background: #4caf50;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    transition: width 0.3s;
}

.tally-nay-bar {
    background: #f44336;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    transition: width 0.3s;
}

.tally-counts {
    display: flex;
    gap: 12px;
    margin-top: 6px;
    font-size: 0.85rem;
}

.tally-counts .tally-yea { color: #4caf50; }
.tally-counts .tally-nay { color: #f44336; }
.tally-counts .tally-total { color: #888; margin-left: auto; }
.tally-counts .tally-passed { color: #d4af37; font-weight: 600; }

/* Multi-choice options */
.ballot-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ballot-option {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: #0d0d1a;
    border: 2px solid #333;
    border-radius: 8px;
    color: #ccc;
    cursor: pointer;
    transition: all 0.15s;
    text-align: left;
    font-size: 0.95rem;
}

.ballot-option:hover:not(:disabled) {
    border-color: #d4af37;
    color: #e0e0e0;
}

.ballot-option.selected {
    border-color: #d4af37;
    background: rgba(212, 175, 55, 0.1);
    color: #d4af37;
}

.ballot-option:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.option-count {
    background: #2a2a3e;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.8rem;
    color: #b0b0b0;
    min-width: 30px;
    text-align: center;
}

.ballot-option.selected .option-count {
    background: #d4af37;
    color: #0a0a0a;
}

/* Ranked choice */
.ballot-ranked-hint {
    color: #b0b0b0;
    font-size: 0.85rem;
    margin-bottom: 8px;
}

.ballot-ranked-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 12px;
}

.ranked-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: #0d0d1a;
    border: 1px solid #333;
    border-radius: 8px;
    cursor: grab;
    user-select: none;
    transition: all 0.15s;
}

.ranked-item:active { cursor: grabbing; }
.ranked-item.dragging { opacity: 0.5; border-color: #d4af37; }
.ranked-item.drag-over { border-color: #4fc3f7; border-style: dashed; }

.ranked-num {
    background: #d4af37;
    color: #0a0a0a;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 700;
    flex-shrink: 0;
}

.ranked-text {
    flex: 1;
    color: #ccc;
    font-size: 0.95rem;
}

.ranked-handle {
    color: #666;
    font-size: 1.1rem;
}

.ballot-submit-ranked {
    background: #d4af37;
    color: #0a0a0a;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
}

.ballot-submit-ranked:hover { background: #e4bf47; }
.ballot-submit-ranked:disabled { opacity: 0.5; cursor: not-allowed; }

/* RCV results */
.ballot-rcv-results {
    margin-top: 10px;
    padding: 10px;
    background: #0d0d1a;
    border-radius: 6px;
    color: #b0b0b0;
    font-size: 0.85rem;
}

.ballot-winner {
    color: #d4af37;
    font-weight: 600;
}

/* Login prompt */
.ballot-login-prompt {
    margin-top: 10px;
    text-align: center;
    color: #888;
    font-size: 0.85rem;
}

.ballot-login-prompt a {
    color: #d4af37;
}

/* Toast for vote feedback */
.ballot-toast {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: #1a1a2e;
    border: 1px solid #d4af37;
    color: #d4af37;
    padding: 10px 24px;
    border-radius: 8px;
    font-size: 0.9rem;
    z-index: 9999;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}

.ballot-toast.show {
    opacity: 1;
}

/* Responsive */
@media (max-width: 600px) {
    .ballot-yn-buttons {
        flex-wrap: wrap;
    }
    .ballot-yn-buttons .vote-btn {
        flex: 0 0 calc(50% - 5px);
    }
}
```

**Step 2: Write ballot JS**

```js
/**
 * Ballot JS — Civic Engine Phase 1
 * =================================
 * Client-side voting for yes/no, multi-choice, and ranked-choice ballots.
 */

// Vote on a ballot (yes/no or multi-choice)
async function ballotVote(pollId, voteData) {
    voteData.poll_id = pollId;
    try {
        const res = await fetch('/api/ballot.php?action=vote', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(voteData)
        });
        const data = await res.json();
        if (data.success) {
            ballotShowToast(data.action === 'created' ? 'Vote recorded' : 'Vote updated');
            ballotRefreshCard(pollId);
        } else {
            ballotShowToast(data.error || 'Vote failed', true);
        }
    } catch (e) {
        ballotShowToast('Network error', true);
    }
}

// Submit ranked-choice rankings
async function ballotSubmitRanked(pollId, prefix) {
    const list = document.getElementById(prefix + '-ranked-list');
    if (!list) return;
    const items = list.querySelectorAll('.ranked-item');
    const rankings = {};
    items.forEach((item, i) => {
        rankings[item.dataset.optionId] = i + 1;
    });

    try {
        const res = await fetch('/api/ballot.php?action=vote', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ poll_id: pollId, rankings })
        });
        const data = await res.json();
        if (data.success) {
            ballotShowToast('Rankings submitted');
            ballotRefreshCard(pollId);
        } else {
            ballotShowToast(data.error || 'Submit failed', true);
        }
    } catch (e) {
        ballotShowToast('Network error', true);
    }
}

// Refresh a ballot card after voting
async function ballotRefreshCard(pollId) {
    try {
        const res = await fetch(`/api/ballot.php?action=tally&poll_id=${pollId}`);
        const tally = await res.json();
        const card = document.querySelector(`[data-poll-id="${pollId}"]`);
        if (!card) return;
        const voteType = card.dataset.voteType;

        // Update tally display
        if (voteType === 'yes_no' || voteType === 'yes_no_novote') {
            const total = Math.max(1, tally.total_votes || 0);
            const yeaPct = Math.round((tally.yea || 0) / total * 100);
            const nayPct = Math.round((tally.nay || 0) / total * 100);

            const yeaBar = card.querySelector('.tally-yea-bar');
            const nayBar = card.querySelector('.tally-nay-bar');
            if (yeaBar) { yeaBar.style.width = yeaPct + '%'; yeaBar.textContent = yeaPct > 5 ? yeaPct + '%' : ''; }
            if (nayBar) { nayBar.style.width = nayPct + '%'; nayBar.textContent = nayPct > 5 ? nayPct + '%' : ''; }

            const yCount = card.querySelector('.tally-yea');
            const nCount = card.querySelector('.tally-nay');
            const tCount = card.querySelector('.tally-total');
            if (yCount) yCount.textContent = (tally.yea || 0) + ' Yea';
            if (nCount) nCount.textContent = (tally.nay || 0) + ' Nay';
            if (tCount) tCount.textContent = total + ' total';

            // Threshold badge
            let passed = card.querySelector('.tally-passed');
            if (tally.threshold_met && !passed) {
                const counts = card.querySelector('.tally-counts');
                if (counts) {
                    passed = document.createElement('span');
                    passed.className = 'tally-passed';
                    passed.textContent = 'Threshold met';
                    counts.appendChild(passed);
                }
            } else if (!tally.threshold_met && passed) {
                passed.remove();
            }
        }

        if (voteType === 'multi_choice' && tally.options) {
            tally.options.forEach(opt => {
                const btn = card.querySelector(`[onclick*="option_id:${opt.option_id}"]`);
                if (btn) {
                    const countSpan = btn.querySelector('.option-count');
                    if (countSpan) countSpan.textContent = opt.vote_count;
                }
            });
        }

    } catch (e) {
        console.error('Ballot refresh error:', e);
    }
}

// Toast notification
function ballotShowToast(msg, isError) {
    let toast = document.getElementById('ballot-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'ballot-toast';
        toast.className = 'ballot-toast';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    if (isError) toast.style.borderColor = '#f44336';
    else toast.style.borderColor = '#d4af37';
    toast.classList.add('show');
    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(() => toast.classList.remove('show'), 2500);
}

// Drag-and-drop for ranked choice
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.ballot-ranked-list').forEach(list => {
        let dragItem = null;

        list.addEventListener('dragstart', e => {
            dragItem = e.target.closest('.ranked-item');
            if (dragItem) dragItem.classList.add('dragging');
        });

        list.addEventListener('dragend', e => {
            if (dragItem) dragItem.classList.remove('dragging');
            list.querySelectorAll('.ranked-item').forEach((item, i) => {
                item.querySelector('.ranked-num').textContent = i + 1;
                item.classList.remove('drag-over');
            });
            dragItem = null;
        });

        list.addEventListener('dragover', e => {
            e.preventDefault();
            const target = e.target.closest('.ranked-item');
            if (target && target !== dragItem) {
                const rect = target.getBoundingClientRect();
                const mid = rect.top + rect.height / 2;
                if (e.clientY < mid) {
                    list.insertBefore(dragItem, target);
                } else {
                    list.insertBefore(dragItem, target.nextSibling);
                }
            }
        });

        list.addEventListener('dragenter', e => {
            const target = e.target.closest('.ranked-item');
            if (target && target !== dragItem) target.classList.add('drag-over');
        });

        list.addEventListener('dragleave', e => {
            const target = e.target.closest('.ranked-item');
            if (target) target.classList.remove('drag-over');
        });
    });
});
```

**Step 3: Commit**

```bash
cd /c/tpb && git add assets/ballot.css assets/ballot.js
git commit -m "feat: Ballot CSS + JS — voting UI, drag-drop ranked choice, live tally refresh"
```

---

## Task 11: Create ballot test page — poll/ballots.php

**Files:**
- Create: `poll/ballots.php`

**Step 1: Write the ballots page**

This is the new "Ballots" top-level nav page. It shows scoped ballots (not just threat polls). Initially shows all federal ballots. Later phases add state/town scoping.

```php
<?php
/**
 * Ballots — Civic Engine Phase 1
 * ===============================
 * Universal ballot page showing scoped voting instruments.
 * Coexists with existing poll/index.php (threat polls).
 */

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/ballot.php';

$dbUser = getUser($pdo);

// Scope from URL
$scopeType = $_GET['scope'] ?? 'federal';
$scopeId = $_GET['scope_id'] ?? null;

// Validate scope
if (!in_array($scopeType, ['federal', 'state', 'town', 'group'])) {
    $scopeType = 'federal';
}

// Get ballots for this scope
$ballots = Ballot::listByScope($pdo, $scopeType, $scopeId);

// Can this user vote?
$canVote = $dbUser && ($dbUser['identity_level_id'] ?? 0) >= 2;

$currentPage = 'ballots';
$pageTitle = 'Ballots | The People\'s Branch';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav.php';
?>

<link rel="stylesheet" href="/assets/ballot.css">

<style>
.ballots-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 30px 20px;
}
.ballots-header {
    margin-bottom: 25px;
}
.ballots-header h1 {
    color: #d4af37;
    font-size: 1.8rem;
    margin-bottom: 5px;
}
.ballots-header p {
    color: #b0b0b0;
    font-size: 0.95rem;
}
.scope-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.scope-tab {
    padding: 6px 16px;
    border: 1px solid #333;
    border-radius: 20px;
    background: transparent;
    color: #b0b0b0;
    cursor: pointer;
    font-size: 0.85rem;
    text-decoration: none;
    transition: all 0.15s;
}
.scope-tab:hover { border-color: #d4af37; color: #d4af37; }
.scope-tab.active { background: #d4af37; color: #0a0a0a; border-color: #d4af37; }
.no-ballots {
    text-align: center;
    padding: 60px 20px;
    color: #888;
}
.no-ballots h3 { color: #b0b0b0; margin-bottom: 10px; }
</style>

<div class="ballots-container">
    <div class="ballots-header">
        <h1>Ballots</h1>
        <p>Vote on civic issues. Your voice counts.</p>
    </div>

    <div class="scope-tabs">
        <a href="/poll/ballots.php" class="scope-tab <?= $scopeType === 'federal' ? 'active' : '' ?>">National</a>
        <?php if ($dbUser && !empty($dbUser['current_state_id'])): ?>
            <a href="/poll/ballots.php?scope=state&scope_id=<?= $dbUser['current_state_id'] ?>"
               class="scope-tab <?= $scopeType === 'state' ? 'active' : '' ?>">My State</a>
        <?php endif; ?>
        <?php if ($dbUser && !empty($dbUser['current_town_id'])): ?>
            <a href="/poll/ballots.php?scope=town&scope_id=<?= $dbUser['current_town_id'] ?>"
               class="scope-tab <?= $scopeType === 'town' ? 'active' : '' ?>">My Town</a>
        <?php endif; ?>
    </div>

    <?php if (empty($ballots)): ?>
        <div class="no-ballots">
            <h3>No ballots yet</h3>
            <p>Ballots will appear here as civic issues are raised for voting.</p>
            <p style="margin-top: 15px;"><a href="/poll/" style="color: #d4af37;">View Threat Polls</a></p>
        </div>
    <?php else: ?>
        <?php foreach ($ballots as $b): ?>
            <?php
            $ballot = Ballot::get($pdo, $b['poll_id']);
            $tally = Ballot::tally($pdo, $b['poll_id']);
            $userVote = [];
            if ($dbUser) {
                $stmt = $pdo->prepare("SELECT vote_choice, option_id, rank_position FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $stmt->execute([$b['poll_id'], $dbUser['user_id']]);
                $userVote = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            require __DIR__ . '/../includes/ballot-card.php';
            ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="/assets/ballot.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

**Step 2: Commit**

```bash
cd /c/tpb && git add poll/ballots.php
git commit -m "feat: Ballots page — scoped ballot listing with all vote types"
```

---

## Task 12: Create admin ballot creation — poll/admin-ballot.php

**Files:**
- Create: `poll/admin-ballot.php`

**Step 1: Write the admin ballot creation page**

This extends the existing poll admin with a form for creating any ballot type (not just threat polls). Accessible to admin users only.

```php
<?php
/**
 * Admin Ballot Creator — Civic Engine Phase 1
 * =============================================
 * Create ballots of any type: yes/no, multi-choice, ranked-choice.
 * Admin only (role_id = 1).
 */

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/ballot.php';

$dbUser = getUser($pdo);

// Admin check
$isAdmin = false;
if ($dbUser) {
    $stmt = $pdo->prepare("SELECT 1 FROM user_role_membership WHERE user_id = ? AND role_id = 1");
    $stmt->execute([$dbUser['user_id']]);
    $isAdmin = (bool)$stmt->fetch();
}
if (!$isAdmin) {
    header('Location: /');
    exit;
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = trim($_POST['question'] ?? '');
    $voteType = $_POST['vote_type'] ?? 'yes_no';
    $scopeType = $_POST['scope_type'] ?? 'federal';
    $scopeId = $_POST['scope_id'] ?? null;
    $thresholdType = $_POST['threshold_type'] ?? 'majority';
    $quorumType = $_POST['quorum_type'] ?? 'none';
    $quorumValue = !empty($_POST['quorum_value']) ? (int)$_POST['quorum_value'] : null;

    // Collect options for multi-choice/ranked
    $options = [];
    if (in_array($voteType, ['multi_choice', 'ranked_choice'])) {
        $rawOptions = $_POST['options'] ?? [];
        foreach ($rawOptions as $opt) {
            $opt = trim($opt);
            if ($opt !== '') $options[] = $opt;
        }
    }

    $result = Ballot::create($pdo, [
        'question' => $question,
        'vote_type' => $voteType,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId ?: null,
        'threshold_type' => $thresholdType,
        'quorum_type' => $quorumType,
        'quorum_value' => $quorumValue,
        'created_by' => $dbUser['user_id'],
        'options' => $options,
    ]);

    if (isset($result['error'])) {
        $message = $result['error'];
        $messageType = 'error';
    } else {
        $message = "Ballot #{$result['poll_id']} created successfully!";
        $messageType = 'success';
    }
}

$currentPage = 'admin';
$pageTitle = 'Create Ballot | Admin | The People\'s Branch';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav.php';
?>

<style>
.admin-container { max-width: 700px; margin: 0 auto; padding: 30px 20px; }
.admin-container h1 { color: #d4af37; margin-bottom: 20px; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; color: #ccc; margin-bottom: 5px; font-weight: 600; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 10px; background: #1a1a2e; border: 1px solid #333;
    border-radius: 6px; color: #e0e0e0; font-size: 0.95rem;
}
.form-group textarea { min-height: 80px; resize: vertical; }
.options-list { display: flex; flex-direction: column; gap: 8px; }
.option-row { display: flex; gap: 8px; }
.option-row input { flex: 1; }
.option-row button { background: #333; border: none; color: #f44336; padding: 8px 12px; border-radius: 6px; cursor: pointer; }
.add-option-btn { background: transparent; border: 1px dashed #d4af37; color: #d4af37; padding: 8px; border-radius: 6px; cursor: pointer; width: 100%; margin-top: 8px; }
.submit-btn { background: #d4af37; color: #0a0a0a; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; font-size: 1rem; cursor: pointer; }
.submit-btn:hover { background: #e4bf47; }
.msg { padding: 12px; border-radius: 6px; margin-bottom: 15px; }
.msg.success { background: rgba(76, 175, 80, 0.15); border: 1px solid #4caf50; color: #4caf50; }
.msg.error { background: rgba(244, 67, 54, 0.15); border: 1px solid #f44336; color: #f44336; }
#options-section { display: none; }
</style>

<div class="admin-container">
    <h1>Create Ballot</h1>
    <p style="color: #b0b0b0; margin-bottom: 20px;">Create a new ballot for citizens to vote on.</p>

    <?php if ($message): ?>
        <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Question</label>
            <textarea name="question" required placeholder="What should citizens vote on?"><?= htmlspecialchars($_POST['question'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label>Vote Type</label>
            <select name="vote_type" id="vote-type" onchange="toggleOptions()">
                <option value="yes_no">Yes / No</option>
                <option value="yes_no_novote">Yes / No / No Vote</option>
                <option value="multi_choice">Multi-Choice</option>
                <option value="ranked_choice">Ranked Choice</option>
            </select>
        </div>

        <div id="options-section">
            <div class="form-group">
                <label>Options</label>
                <div class="options-list" id="options-list">
                    <div class="option-row">
                        <input type="text" name="options[]" placeholder="Option 1">
                        <button type="button" onclick="this.parentElement.remove()">X</button>
                    </div>
                    <div class="option-row">
                        <input type="text" name="options[]" placeholder="Option 2">
                        <button type="button" onclick="this.parentElement.remove()">X</button>
                    </div>
                </div>
                <button type="button" class="add-option-btn" onclick="addOption()">+ Add Option</button>
            </div>
        </div>

        <div class="form-group">
            <label>Scope</label>
            <select name="scope_type">
                <option value="federal">Federal (National)</option>
                <option value="state">State</option>
                <option value="town">Town</option>
                <option value="group">Group</option>
            </select>
        </div>

        <div class="form-group">
            <label>Scope ID (state abbr, town slug, or group ID — leave blank for federal)</label>
            <input type="text" name="scope_id" placeholder="e.g. CT, CT-putnam, 42">
        </div>

        <div class="form-group">
            <label>Threshold</label>
            <select name="threshold_type">
                <option value="plurality">Plurality (most votes wins)</option>
                <option value="majority" selected>Majority (>50%)</option>
                <option value="three_fifths">Three-Fifths (>60%)</option>
                <option value="two_thirds">Two-Thirds (>66.7%)</option>
                <option value="three_quarters">Three-Quarters (>75%)</option>
                <option value="unanimous">Unanimous (100%)</option>
            </select>
        </div>

        <div class="form-group">
            <label>Quorum</label>
            <select name="quorum_type">
                <option value="none" selected>None (any participation counts)</option>
                <option value="minimum">Minimum votes required</option>
                <option value="percent">Percentage of eligible</option>
            </select>
        </div>

        <div class="form-group">
            <label>Quorum Value (if applicable)</label>
            <input type="number" name="quorum_value" placeholder="e.g. 10 for minimum, or 50 for percent">
        </div>

        <button type="submit" class="submit-btn">Create Ballot</button>
    </form>

    <p style="margin-top: 20px;"><a href="/poll/admin.php" style="color: #88c0d0;">Back to Poll Admin</a> | <a href="/poll/ballots.php" style="color: #88c0d0;">View Ballots</a></p>
</div>

<script>
function toggleOptions() {
    const vt = document.getElementById('vote-type').value;
    document.getElementById('options-section').style.display =
        (vt === 'multi_choice' || vt === 'ranked_choice') ? 'block' : 'none';
}
function addOption() {
    const list = document.getElementById('options-list');
    const n = list.children.length + 1;
    const row = document.createElement('div');
    row.className = 'option-row';
    row.innerHTML = `<input type="text" name="options[]" placeholder="Option ${n}"><button type="button" onclick="this.parentElement.remove()">X</button>`;
    list.appendChild(row);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

**Step 2: Commit**

```bash
cd /c/tpb && git add poll/admin-ballot.php
git commit -m "feat: Admin ballot creator — all vote types, scopes, thresholds"
```

---

## Task 13: Push experiment branch and deploy to test server

**Step 1: Push all commits to GitHub**

```bash
cd /c/tpb && git push origin experiment
```

**Step 2: Pull on test server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb.sandgems.net && git pull origin experiment"
```

**Step 3: Run the migration SQL on the database**

(See Task 6 for the SSH command to run the SQL.)

**Step 4: Verify the ballots page loads**

Visit: `https://tpb.sandgems.net/poll/ballots.php`

Expected: Page loads with "No ballots yet" message and National scope tab.

**Step 5: Create a test ballot via admin**

Visit: `https://tpb.sandgems.net/poll/admin-ballot.php`

Create one of each type:
1. Yes/No ballot: "Should TPB add a town calendar feature?"
2. Multi-choice ballot: "Which feature should we build next?" with 3 options
3. Ranked-choice ballot: "Rank these civic priorities" with 4 options

**Step 6: Verify voting works on ballots page**

Visit: `https://tpb.sandgems.net/poll/ballots.php`

- Vote on the yes/no ballot — tally bar should update
- Vote on multi-choice — selected option count should update
- Drag-rank and submit ranked choice — confirm rankings saved

---

## Task 14: Update system cross-reference

**Files:**
- Modify: Memory file `system-xref.md`

Add new files to the cross-reference:
- `includes/ballot.php` — Infrastructure (Ballot helper class)
- `api/ballot.php` — API (ballot create/vote/tally/get/list)
- `assets/ballot.css` — Assets (ballot card styles)
- `assets/ballot.js` — Assets (ballot voting JS)
- `includes/ballot-card.php` — Infrastructure (reusable ballot card component)
- `poll/ballots.php` — Ballots (top-level nav) — scoped ballot listing
- `poll/admin-ballot.php` — Admin only — ballot creation form

---

## Phase 2-5: Future Plans

These phases build on Phase 1's foundation. Each will get its own implementation plan document when Phase 1 is complete and tested.

### Phase 2: Group Deliberation (docs/plans/2026-xx-xx-civic-engine-phase2.md)
- Facilitator tools in talk/groups.php (surface option from post, call vote)
- Embed ballot-card.php in group detail view
- Multi-round voting with merge/revise between rounds
- Create declarations table
- Declaration output from successful group vote

### Phase 3: Public Opinion + Pulse Extension (docs/plans/2026-xx-xx-civic-engine-phase3.md)
- Create public_opinions table
- Opinion UI on declarations and mandates (agree/disagree/mixed)
- Group declarations feed in mandate-summary.php
- Town dashboard section (z-states pages get #dashboard anchor)
- State/federal roll-up views

### Phase 4: The Feed (docs/plans/2026-xx-xx-civic-engine-phase4.md)
- Generalize poll/admin.php threat-sync to universal feed engine
- Bill feed → auto-ballot
- Executive order feed → auto-ballot
- Declaration escalation → parent jurisdiction ballot

### Phase 5: The Fractal (docs/plans/2026-xx-xx-civic-engine-phase5.md)
- Town-level group-of-groups deliberation
- State-level town roll-up
- Federal-level state roll-up
- Representative targeting ("Beam to Desk")

---

## Nav Changes (Deferred)

The design doc specifies nav restructuring (Row 2 changes, sub-navs, footer redesign). These are deferred to a separate plan (`2026-xx-xx-nav-restructure.md`) because:
1. Nav changes are high-visibility, high-risk — touch every page
2. Phase 1 features work within the existing nav (ballots.php linked from polls section)
3. Nav restructure should happen once, not incrementally per phase
