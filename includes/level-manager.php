<?php
/**
 * TPB Level Manager
 * =================
 * Centralized identity level advancement
 * 
 * Checks user's verification status and auto-advances level when qualified.
 * Awards points via PointLogger for audit trail.
 * 
 * Usage:
 *   require_once __DIR__ . '/level-manager.php';
 *   $result = LevelManager::checkAndAdvance($pdo, $userId);
 * 
 * Level progression:
 *   1 (anonymous)   → 2 (remembered) : email verified
 *   2 (remembered)  → 3 (verified)   : phone verified  
 *   3 (verified)    → 4 (vetted)     : background checked (manual)
 */

require_once __DIR__ . '/point-logger.php';

class LevelManager {
    
    // Level IDs (match identity_levels table)
    const LEVEL_ANONYMOUS  = 1;
    const LEVEL_REMEMBERED = 2;
    const LEVEL_VERIFIED   = 3;
    const LEVEL_VETTED     = 4;
    
    // Level names for logging
    private static $levelNames = [
        1 => 'Anonymous',
        2 => 'Remembered', 
        3 => 'Verified',
        4 => 'Vetted'
    ];
    
    /**
     * Check user's verification status and advance level if qualified
     * 
     * @param PDO $pdo - Database connection
     * @param int $userId - User to check
     * @return array - Result with level info and any advancement
     */
    public static function checkAndAdvance($pdo, $userId) {
        // Get current user status
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.identity_level_id,
                   COALESCE(uis.email_verified, 0) as email_verified,
                   COALESCE(uis.phone_verified, 0) as phone_verified,
                   COALESCE(uis.background_checked, 0) as background_checked
            FROM users u
            LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
            WHERE u.user_id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }
        
        $currentLevel = (int)$user['identity_level_id'];
        $qualifiedLevel = self::calculateQualifiedLevel($user);
        
        // No advancement needed
        if ($qualifiedLevel <= $currentLevel) {
            return [
                'success' => true,
                'advanced' => false,
                'current_level' => $currentLevel,
                'level_name' => self::$levelNames[$currentLevel] ?? 'Unknown'
            ];
        }
        
        // Advance the user
        return self::advanceToLevel($pdo, $userId, $currentLevel, $qualifiedLevel);
    }
    
    /**
     * Calculate what level user qualifies for based on verification flags
     */
    private static function calculateQualifiedLevel($user) {
        if ($user['background_checked']) {
            return self::LEVEL_VETTED;
        }
        if ($user['phone_verified']) {
            return self::LEVEL_VERIFIED;
        }
        if ($user['email_verified']) {
            return self::LEVEL_REMEMBERED;
        }
        return self::LEVEL_ANONYMOUS;
    }
    
    /**
     * Advance user to new level, update both tables, award points
     */
    private static function advanceToLevel($pdo, $userId, $fromLevel, $toLevel) {
        // Update users table
        $stmt = $pdo->prepare("UPDATE users SET identity_level_id = ? WHERE user_id = ?");
        $stmt->execute([$toLevel, $userId]);
        
        // Update user_identity_status table
        $stmt = $pdo->prepare("UPDATE user_identity_status SET identity_level_id = ? WHERE user_id = ?");
        $stmt->execute([$toLevel, $userId]);
        
        // Award points for each level gained
        PointLogger::init($pdo);
        $pointsAwarded = 0;
        $advancements = [];
        
        // Walk through each level advancement
        for ($level = $fromLevel + 1; $level <= $toLevel; $level++) {
            $actionName = self::getActionForLevel($level);
            if ($actionName) {
                $result = PointLogger::award($userId, $actionName, 'identity', $level);
                if ($result['success'] && isset($result['points_earned'])) {
                    $pointsAwarded += $result['points_earned'];
                }
                $advancements[] = [
                    'to_level' => $level,
                    'level_name' => self::$levelNames[$level],
                    'action' => $actionName,
                    'points' => $result['points_earned'] ?? 0
                ];
            }
        }
        
        return [
            'success' => true,
            'advanced' => true,
            'from_level' => $fromLevel,
            'from_level_name' => self::$levelNames[$fromLevel] ?? 'Unknown',
            'to_level' => $toLevel,
            'to_level_name' => self::$levelNames[$toLevel] ?? 'Unknown',
            'points_awarded' => $pointsAwarded,
            'advancements' => $advancements
        ];
    }
    
    /**
     * Get the point action name for reaching a level
     */
    private static function getActionForLevel($level) {
        switch ($level) {
            case self::LEVEL_REMEMBERED:
                return 'email_verified';
            case self::LEVEL_VERIFIED:
                return 'phone_verified';
            case self::LEVEL_VETTED:
                return null; // No automatic points for vetted (manual process)
            default:
                return null;
        }
    }
    
    /**
     * Get user's current level info (read-only, no advancement)
     */
    public static function getLevel($pdo, $userId) {
        $stmt = $pdo->prepare("
            SELECT u.identity_level_id, il.level_name, il.can_view, il.can_vote, il.can_post, il.can_respond
            FROM users u
            LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
            WHERE u.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get permissions for a specific level
     */
    public static function getPermissions($pdo, $levelId) {
        $stmt = $pdo->prepare("
            SELECT can_view, can_vote, can_post, can_respond
            FROM identity_levels
            WHERE level_id = ?
        ");
        $stmt->execute([$levelId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if user has a specific permission
     */
    public static function canDo($pdo, $userId, $permission) {
        $level = self::getLevel($pdo, $userId);
        if (!$level) return false;
        
        $field = 'can_' . $permission;
        return isset($level[$field]) && $level[$field];
    }
}
