<?php
/**
 * TPB Point Logger — Unified Points Engine
 * ==========================================
 * All points flow through this single gateway.
 * Writes to: points_log (unified table) + users.civic_points (running total)
 * 
 * Usage:
 *   require_once __DIR__ . '/point-logger.php';
 *   PointLogger::init($pdo);
 *
 *   // Logged-in user actions:
 *   PointLogger::award($userId, 'vote_cast', 'thought', $thoughtId);
 *
 *   // Anonymous/session actions:
 *   PointLogger::awardSession($sessionId, 'page_visit', 'index', null, null, $extraJson);
 *
 *   // Transfer anon points when user logs in or registers:
 *   PointLogger::transferSession($sessionId, $userId);
 *
 *   // Milestone with variable points:
 *   PointLogger::awardMilestone($userId, $milestoneId, $points);
 */

class PointLogger {
    private static $pdo = null;
    private static $actions = null;
    
    /**
     * Initialize with PDO connection
     */
    public static function init($pdo) {
        self::$pdo = $pdo;
        self::loadActions();
    }
    
    /**
     * Load action definitions from point_actions table
     */
    private static function loadActions() {
        if (self::$actions !== null) return;
        
        $stmt = self::$pdo->query("SELECT * FROM point_actions WHERE is_active = 1");
        self::$actions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            self::$actions[$row['action_name']] = $row;
        }
    }
    
    /**
     * Award points for a logged-in user action
     * 
     * @param int    $userId      User receiving points
     * @param string $actionName  Action name from point_actions table
     * @param string $contextType Optional: 'thought', 'vote', 'identity', etc.
     * @param mixed  $contextId   Optional: ID of related record
     * @param string $pageName    Optional: which page triggered this
     * @param string $extraData   Optional: JSON string of extra data
     * @return array Result with points_earned or error
     */
    public static function award($userId, $actionName, $contextType = null, $contextId = null, $pageName = null, $extraData = null) {
        if (!self::$pdo) {
            return ['success' => false, 'error' => 'PointLogger not initialized'];
        }
        
        // Get action definition
        if (!isset(self::$actions[$actionName])) {
            return ['success' => false, 'error' => 'Unknown action: ' . $actionName];
        }
        
        $action = self::$actions[$actionName];
        $actionId = $action['action_id'];
        $points = (int)$action['points_value'];
        
        // Check cooldown (by user_id)
        if ($action['cooldown_hours'] > 0) {
            $stmt = self::$pdo->prepare("
                SELECT COUNT(*) as recent
                FROM points_log
                WHERE user_id = ? AND action_id = ?
                  AND earned_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$userId, $actionId, $action['cooldown_hours']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['recent'] > 0) {
                return ['success' => false, 'error' => 'Cooldown active', 'cooldown_hours' => $action['cooldown_hours']];
            }
        }
        
        // Check daily limit (by user_id)
        if ($action['daily_limit']) {
            $stmt = self::$pdo->prepare("
                SELECT COUNT(*) as today_count
                FROM points_log
                WHERE user_id = ? AND action_id = ?
                  AND DATE(earned_at) = CURDATE()
            ");
            $stmt->execute([$userId, $actionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['today_count'] >= $action['daily_limit']) {
                return ['success' => false, 'error' => 'Daily limit reached', 'daily_limit' => $action['daily_limit']];
            }
        }
        
        // Skip if zero points (but still log for milestone actions)
        if ($points == 0 && $actionName !== 'milestone_achieved') {
            return ['success' => true, 'points_earned' => 0, 'skipped' => true];
        }
        
        // Insert to points_log
        $stmt = self::$pdo->prepare("
            INSERT INTO points_log (user_id, action_id, points_earned, context_type, context_id, page_name, extra_data)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $actionId, $points, $contextType, $contextId, $pageName, $extraData]);
        
        // Update user's running total
        $stmt = self::$pdo->prepare("
            UPDATE users SET civic_points = civic_points + ? WHERE user_id = ?
        ");
        $stmt->execute([$points, $userId]);
        
        return [
            'success' => true,
            'points_earned' => $points,
            'action' => $actionName,
            'total_added' => $points
        ];
    }
    
    /**
     * Award points for an anonymous/session action (no user_id yet)
     * 
     * @param string $sessionId   Session cookie value
     * @param string $actionName  Action name from point_actions table
     * @param string $pageName    Which page triggered this
     * @param string $contextId   Optional: element_id or reference
     * @param string $contextType Optional: override context (defaults to action_name)
     * @param string $extraData   Optional: JSON string of extra data
     * @return array Result with points_earned or error
     */
    public static function awardSession($sessionId, $actionName, $pageName = null, $contextId = null, $contextType = null, $extraData = null) {
        if (!self::$pdo) {
            return ['success' => false, 'error' => 'PointLogger not initialized'];
        }
        
        if (!$sessionId) {
            return ['success' => false, 'error' => 'No session_id provided'];
        }
        
        // Get action definition
        if (!isset(self::$actions[$actionName])) {
            // Unknown action — log it anyway with 1 point so we don't lose data
            $actionId = null;
            $points = 1;
            $dailyLimit = null;
        } else {
            $action = self::$actions[$actionName];
            $actionId = $action['action_id'];
            $points = (int)$action['points_value'];
            $dailyLimit = $action['daily_limit'];
        }
        
        // Check daily limit (by session_id)
        if ($dailyLimit) {
            $stmt = self::$pdo->prepare("
                SELECT COUNT(*) as today_count
                FROM points_log
                WHERE session_id = ? AND action_id = ?
                  AND DATE(earned_at) = CURDATE()
            ");
            $stmt->execute([$sessionId, $actionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['today_count'] >= $dailyLimit) {
                return ['success' => false, 'error' => 'Daily limit reached', 'daily_limit' => $dailyLimit];
            }
        }
        
        // Insert to points_log (no user_id, session_id only)
        $stmt = self::$pdo->prepare("
            INSERT INTO points_log (session_id, action_id, points_earned, context_type, context_id, page_name, extra_data)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sessionId,
            $actionId,
            $points,
            $contextType ?? $actionName,
            $contextId,
            $pageName,
            $extraData
        ]);
        
        return [
            'success' => true,
            'points_earned' => $points,
            'action' => $actionName,
            'log_id' => self::$pdo->lastInsertId()
        ];
    }
    
    /**
     * Transfer anonymous session points to a user account
     * Called when anon becomes remembered (login, register, verify)
     * 
     * - Stamps all session rows with the user_id
     * - Adds session point total to users.civic_points
     * - Returns total points transferred
     * 
     * @param string $sessionId  The anonymous session cookie value
     * @param int    $userId     The user_id to transfer points to
     * @return array Result with points_transferred
     */
    public static function transferSession($sessionId, $userId) {
        if (!self::$pdo) {
            return ['success' => false, 'error' => 'PointLogger not initialized'];
        }
        
        if (!$sessionId || !$userId) {
            return ['success' => false, 'error' => 'Missing sessionId or userId'];
        }
        
        // Get total session points that haven't been transferred yet
        // (user_id IS NULL means not yet claimed)
        $stmt = self::$pdo->prepare("
            SELECT COALESCE(SUM(points_earned), 0) as total
            FROM points_log
            WHERE session_id = ? AND user_id IS NULL
        ");
        $stmt->execute([$sessionId]);
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total === 0) {
            return ['success' => true, 'points_transferred' => 0, 'message' => 'No unclaimed session points'];
        }
        
        // Stamp all unclaimed session rows with the user_id
        $stmt = self::$pdo->prepare("
            UPDATE points_log
            SET user_id = ?
            WHERE session_id = ? AND user_id IS NULL
        ");
        $stmt->execute([$userId, $sessionId]);
        $rowsUpdated = $stmt->rowCount();
        
        // Add total to user's running total
        $stmt = self::$pdo->prepare("
            UPDATE users SET civic_points = civic_points + ? WHERE user_id = ?
        ");
        $stmt->execute([$total, $userId]);
        
        return [
            'success' => true,
            'points_transferred' => $total,
            'rows_updated' => $rowsUpdated
        ];
    }
    
    /**
     * Award milestone points (variable amount from progression_milestones)
     * 
     * @param int    $userId      User receiving points
     * @param int    $milestoneId FK to progression_milestones (can be null)
     * @param int    $points      Points to award
     * @param string $contextType Optional context label
     * @return array Result with points_earned
     */
    public static function awardMilestone($userId, $milestoneId, $points, $contextType = 'milestone') {
        if (!self::$pdo) {
            return ['success' => false, 'error' => 'PointLogger not initialized'];
        }
        
        // Get milestone action ID (action_id 1 = milestone_achieved)
        $actionId = self::$actions['milestone_achieved']['action_id'] ?? null;
        
        // Insert to points_log
        $stmt = self::$pdo->prepare("
            INSERT INTO points_log (user_id, action_id, milestone_id, points_earned, context_type, context_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $actionId, $milestoneId, $points, $contextType, $milestoneId]);
        
        // Update user's running total
        $stmt = self::$pdo->prepare("
            UPDATE users SET civic_points = civic_points + ? WHERE user_id = ?
        ");
        $stmt->execute([$points, $userId]);
        
        return [
            'success' => true,
            'points_earned' => $points,
            'milestone_id' => $milestoneId
        ];
    }
    
    /**
     * Get session points total (for displaying to anon users)
     * 
     * @param string $sessionId Session cookie value
     * @return int Total points for this session
     */
    public static function getSessionTotal($sessionId) {
        if (!self::$pdo || !$sessionId) {
            return 0;
        }
        
        $stmt = self::$pdo->prepare("
            SELECT COALESCE(SUM(points_earned), 0) as total
            FROM points_log
            WHERE session_id = ?
        ");
        $stmt->execute([$sessionId]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
    
    /**
     * Get session history (for UI decisions — what has this user already done)
     * 
     * @param string $sessionId Session cookie value
     * @return array Rows with action details
     */
    public static function getSessionHistory($sessionId) {
        if (!self::$pdo || !$sessionId) {
            return [];
        }
        
        $stmt = self::$pdo->prepare("
            SELECT DISTINCT context_type, context_id, extra_data
            FROM points_log
            WHERE session_id = ?
            ORDER BY earned_at DESC
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get user's point history
     * 
     * @param int $userId User ID
     * @param int $limit  Max rows to return
     * @return array Point history rows with action details
     */
    public static function getHistory($userId, $limit = 50) {
        if (!self::$pdo) {
            return [];
        }
        
        $stmt = self::$pdo->prepare("
            SELECT pl.*, pa.action_name, pa.description
            FROM points_log pl
            LEFT JOIN point_actions pa ON pl.action_id = pa.action_id
            WHERE pl.user_id = ?
            ORDER BY pl.earned_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Recalculate user's total points from points_log
     * Use for auditing — compares log total vs users.civic_points
     * 
     * @param int $userId User ID
     * @return int Recalculated total
     */
    public static function recalculateTotal($userId) {
        if (!self::$pdo) {
            return 0;
        }
        
        $stmt = self::$pdo->prepare("
            SELECT COALESCE(SUM(points_earned), 0) as total
            FROM points_log
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
}
