<?php
/**
 * Skill Progression Manager
 * Handles skill point allocation and level progression when users complete quests
 */

// Use path relative to this includes directory
require_once __DIR__ . '/config.php';

class SkillProgression {
    private $pdo;
    // Default in-code thresholds (fallback if DB table not present)
    private array $defaultThresholds = [
        1 => 0,
        2 => 100,
        3 => 260,
        4 => 510,
        5 => 900,
        6 => 1500,
        7 => 2420,
        8 => 4600,
        9 => 7700,
        10 => 12700,
        11 => 19300,
        12 => 29150,
    ];
    private array $thresholds = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadThresholds();
    }

    /**
     * Attempt to load thresholds from DB; create/seed if missing; fallback to defaults.
     */
    private function loadThresholds(): void {
        $this->thresholds = $this->defaultThresholds; // preset fallback
        try {
            // Create table if not exists (idempotent)
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS skill_level_thresholds (
                id INT NOT NULL AUTO_INCREMENT,
                level INT NOT NULL,
                cumulative_xp INT NOT NULL,
                stage ENUM('Learning','Applying','Mastering','Innovating') NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_level (level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // Check if rows exist
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM skill_level_thresholds");
            $count = (int)$stmt->fetchColumn();
            if ($count === 0) {
                // Seed initial thresholds
                $seed = [
                    [1,0,'Learning'],[2,100,'Learning'],[3,260,'Learning'],
                    [4,510,'Applying'],[5,900,'Applying'],[6,1500,'Applying'],
                    [7,2420,'Mastering'],[8,4600,'Mastering'],[9,7700,'Mastering'],
                    [10,12700,'Innovating'],[11,19300,'Innovating'],[12,29150,'Innovating']
                ];
                $ins = $this->pdo->prepare("INSERT INTO skill_level_thresholds (level,cumulative_xp,stage) VALUES (?,?,?)");
                foreach ($seed as $row) { $ins->execute($row); }
            }

            // Load active thresholds
            $stmt = $this->pdo->query("SELECT level, cumulative_xp, stage FROM skill_level_thresholds WHERE active = 1 ORDER BY level ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $loaded = [];
            foreach ($rows as $r) {
                $lvl = (int)$r['level'];
                $loaded[$lvl] = (int)$r['cumulative_xp'];
            }
            if ($loaded) { $this->thresholds = $loaded; }
        } catch (Throwable $e) {
            // Fallback silently to default thresholds
            error_log('SkillProgression threshold load fallback: ' . $e->getMessage());
        }
    }
    
    /**
     * Award skill points to a user for quest completion
     * @param int $user_id
     * @param string $skill_name
     * @param int $base_points
     * @param float $performance_multiplier (0.7 = Below, 1.0 = Meets, 1.25 = Exceeds, 1.5 = Exceptional)
     */
    public function awardSkillPoints($user_id, $skill_name, $base_points, $performance_multiplier = 1.0) {
        try {
            $awarded_points = round($base_points * $performance_multiplier);
            
            // Check if user already has this skill
            $stmt = $this->pdo->prepare("SELECT * FROM user_earned_skills WHERE user_id = ? AND skill_name = ?");
            $stmt->execute([$user_id, $skill_name]);
            $existing_skill = $stmt->fetch();
            
            if ($existing_skill) {
                // Update existing skill
                $new_total = $existing_skill['total_points'] + $awarded_points;
                $new_level = $this->calculateLevel($new_total);
                $new_stage = $this->calculateStage($new_level);
                
                $stmt = $this->pdo->prepare("
                    UPDATE user_earned_skills 
                    SET total_points = ?, 
                        current_level = ?, 
                        current_stage = ?, 
                        last_used = NOW(), 
                        recent_points = ?, 
                        status = 'ACTIVE',
                        updated_at = NOW()
                    WHERE user_id = ? AND skill_name = ?
                ");
                $stmt->execute([$new_total, $new_level, $new_stage, $awarded_points, $user_id, $skill_name]);
            } else {
                // Create new skill record
                $level = $this->calculateLevel($awarded_points);
                $stage = $this->calculateStage($level);
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO user_earned_skills 
                    (user_id, skill_name, total_points, current_level, current_stage, last_used, recent_points, status) 
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, 'ACTIVE')
                ");
                $stmt->execute([$user_id, $skill_name, $awarded_points, $level, $stage, $awarded_points]);
            }
            
            return [
                'success' => true,
                'points_awarded' => $awarded_points,
                'new_total' => $new_total ?? $awarded_points,
                'new_level' => $new_level ?? $level,
                'new_stage' => $new_stage ?? $stage
            ];
            
        } catch (PDOException $e) {
            error_log("Error awarding skill points: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Calculate level from total points
     */
    private function calculateLevel(int $points): int {
        $level = 1;
        foreach ($this->thresholds as $lvl => $xp) {
            if ($points >= $xp) {
                $level = $lvl;
            } else {
                break;
            }
        }
        return $level;
    }

    public function getThresholds(): array {
        return $this->thresholds;
    }

    /**
     * Progress meta for UI: returns associative array with
     * level, stage, current_floor, next_floor, xp_into_level, xp_to_next, percent_to_next
     */
    public function getProgressMeta(int $points): array {
        $level = $this->calculateLevel($points);
        $stage = $this->calculateStage($level);
    $currentFloor = $this->thresholds[$level] ?? 0;
        // If max level reached, next floor is null
        $nextFloor = $this->thresholds[$level + 1] ?? null;
        $xpInto = $points - $currentFloor;
        if ($xpInto < 0) $xpInto = 0;
        $xpToNext = $nextFloor !== null ? max(0, $nextFloor - $points) : 0;
        $denom = $nextFloor !== null ? max(1, $nextFloor - $currentFloor) : 1;
        $percent = $nextFloor !== null ? round(($xpInto / $denom) * 100, 1) : 100.0;
        return [
            'level' => $level,
            'stage' => $stage,
            'current_floor' => $currentFloor,
            'next_floor' => $nextFloor,
            'xp_into_level' => $xpInto,
            'xp_to_next' => $xpToNext,
            'percent_to_next' => $percent
        ];
    }
    
    /**
     * Calculate stage from level
     */
    private function calculateStage(int $level): string {
        if ($level <= 3) return 'Learning';
        if ($level <= 6) return 'Applying';
        if ($level <= 9) return 'Mastering';
        return 'Innovating';
    }
    
    /**
     * Get skills for a quest (when creating/editing quests)
     */
    public function getQuestSkills($quest_id) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM quest_skills WHERE quest_id = ?");
            $stmt->execute([$quest_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting quest skills: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Set skills for a quest (when creating/editing quests)
     */
    public function setQuestSkills($quest_id, $skills) {
        try {
            // Delete existing skills for this quest
            $stmt = $this->pdo->prepare("DELETE FROM quest_skills WHERE quest_id = ?");
            $stmt->execute([$quest_id]);
            
            // Insert new skills
            if (!empty($skills)) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO quest_skills (quest_id, skill_name, base_points, tier_level) 
                    VALUES (?, ?, ?, ?)
                ");
                
                // Canonical base points mapping for tiers T1..T5
                $tierToBase = [1 => 2, 2 => 5, 3 => 12, 4 => 25, 5 => 50];
                foreach ($skills as $skill) {
                    // Determine base_points: prefer explicit, otherwise derive from tier_level/tier
                    $bp = null;
                    if (isset($skill['base_points']) && $skill['base_points'] !== null) {
                        $bp = (int)$skill['base_points'];
                    } else {
                        $tierRaw = $skill['tier_level'] ?? $skill['tier'] ?? null;
                        $t = 1;
                        if ($tierRaw !== null) {
                            if (is_numeric($tierRaw)) {
                                $t = intval($tierRaw);
                            } elseif (is_string($tierRaw) && preg_match('/T?([1-5])/i', $tierRaw, $m)) {
                                $t = intval($m[1]);
                            }
                        }
                        $bp = $tierToBase[$t] ?? $tierToBase[1];
                    }
                    $stmt->execute([
                        $quest_id,
                        $skill['name'],
                        $bp,
                        $skill['tier_level'] ?? ($skill['tier'] ?? 'T1')
                    ]);
                }
            }
            
            return ['success' => true];
        } catch (PDOException $e) {
            error_log("Error setting quest skills: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update skill status based on usage (call this periodically)
     */
    public function updateSkillStatuses() {
        try {
            // Mark skills as STALE if not used in 30 days
            $stmt = $this->pdo->prepare("
                UPDATE user_earned_skills 
                SET status = 'STALE' 
                WHERE last_used < DATE_SUB(NOW(), INTERVAL 30 DAY) 
                AND last_used >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND status = 'ACTIVE'
            ");
            $stmt->execute();
            
            // Mark skills as RUSTY if not used in 90 days
            $stmt = $this->pdo->prepare("
                UPDATE user_earned_skills 
                SET status = 'RUSTY' 
                WHERE last_used < DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND status IN ('ACTIVE', 'STALE')
            ");
            $stmt->execute();
            
            return ['success' => true];
        } catch (PDOException $e) {
            error_log("Error updating skill statuses: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get user's overall level and statistics
     */
    public function getUserOverallStats($user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    SUM(total_points) as total_points,
                    COUNT(*) as total_skills,
                    SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) as active_skills
                FROM user_earned_skills 
                WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $total_points = $stats['total_points'] ?? 0;
            $overall_level = $this->calculateLevel($total_points);
            $overall_stage = $this->calculateStage($overall_level);
            
            return [
                'overall_level' => $overall_level,
                'overall_stage' => $overall_stage,
                'total_points' => $total_points,
                'total_skills' => $stats['total_skills'] ?? 0,
                'active_skills' => $stats['active_skills'] ?? 0
            ];
        } catch (PDOException $e) {
            error_log("Error getting user overall stats: " . $e->getMessage());
            return [
                'overall_level' => 1,
                'overall_stage' => 'Learning',
                'total_points' => 0,
                'total_skills' => 0,
                'active_skills' => 0
            ];
        }
    }
    
    /**
     * Resync current_level and current_stage from total_points for all users' skills.
     * Call after changing skill_level_thresholds to ensure consistency.
     * @return array { success: bool, updated: int, error?: string }
     */
    public function resyncAllUserSkillLevels(): array {
        try {
            $sql = "SELECT id, total_points, current_level, current_stage FROM user_earned_skills";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $updated = 0;
            if (!$rows) { return ['success' => true, 'updated' => 0]; }
            $this->pdo->beginTransaction();
            $upd = $this->pdo->prepare("UPDATE user_earned_skills SET current_level = ?, current_stage = ?, updated_at = NOW() WHERE id = ?");
            foreach ($rows as $r) {
                $points = (int)($r['total_points'] ?? 0);
                $meta = $this->getProgressMeta($points);
                $lvl = (int)($meta['level'] ?? 1);
                $stg = (string)($meta['stage'] ?? 'Learning');
                if ((int)$r['current_level'] !== $lvl || (string)$r['current_stage'] !== $stg) {
                    $upd->execute([$lvl, $stg, (int)$r['id']]);
                    $updated++;
                }
            }
            $this->pdo->commit();
            return ['success' => true, 'updated' => $updated];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            error_log('resyncAllUserSkillLevels failed: ' . $e->getMessage());
            return ['success' => false, 'updated' => 0, 'error' => $e->getMessage()];
        }
    }
}

// Example usage when a user completes a quest:
/*
$skillManager = new SkillProgression($pdo);

// Award points for quest completion
$result = $skillManager->awardSkillPoints(
    user_id: 1,
    skill_name: 'PHP',
    base_points: 40,
    performance_multiplier: 1.25  // Exceeds expectations
);

if ($result['success']) {
    echo "Awarded {$result['points_awarded']} points! New level: {$result['new_level']}";
}
*/
?>