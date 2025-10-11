<?php
/**
 * Skill Progression Manager
 * Handles skill point allocation and level progression when users complete quests
 */

// Use path relative to this includes directory
require_once __DIR__ . '/config.php';

class SkillProgression {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
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
    private function calculateLevel($points) {
        if ($points < 100) return 1;      // Beginner
        if ($points < 300) return 2;      // Novice
        if ($points < 700) return 3;      // Competent
        if ($points < 1500) return 4;     // Proficient
        if ($points < 3000) return 5;     // Advanced
        if ($points < 6000) return 6;     // Expert
        return 7;                         // Master
    }
    
    /**
     * Calculate stage from level
     */
    private function calculateStage($level) {
        if ($level <= 3) return 'Learning';
        if ($level <= 5) return 'Applying';
        if ($level <= 6) return 'Mastering';
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
                
                foreach ($skills as $skill) {
                    $stmt->execute([
                        $quest_id,
                        $skill['name'],
                        $skill['base_points'] ?? 25,
                        $skill['tier_level'] ?? 'T1'
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