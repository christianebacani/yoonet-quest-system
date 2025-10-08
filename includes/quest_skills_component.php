<!-- Skill Selection Component for Quest Creation -->
<div class="form-group">
    <label class="form-label">
        <i class="fas fa-tools"></i> Skills Required for This Quest
    </label>
    <p class="form-description">Select the skills that will be assessed when this quest is completed.</p>
    
    <div id="quest-skills-container">
        <div class="skill-selection-grid">
            <!-- Common skills with pre-defined tiers -->
            <div class="skill-item" data-skill="PHP">
                <div class="skill-header">
                    <input type="checkbox" id="skill_php" name="quest_skills[]" value="PHP">
                    <label for="skill_php" class="skill-name">PHP Programming</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[PHP]" class="tier-select">
                        <option value="T1">T1 (25 pts)</option>
                        <option value="T2" selected>T2 (40 pts)</option>
                        <option value="T3">T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="Python">
                <div class="skill-header">
                    <input type="checkbox" id="skill_python" name="quest_skills[]" value="Python">
                    <label for="skill_python" class="skill-name">Python Programming</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[Python]" class="tier-select">
                        <option value="T1">T1 (25 pts)</option>
                        <option value="T2" selected>T2 (40 pts)</option>
                        <option value="T3">T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="JavaScript">
                <div class="skill-header">
                    <input type="checkbox" id="skill_javascript" name="quest_skills[]" value="JavaScript">
                    <label for="skill_javascript" class="skill-name">JavaScript</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[JavaScript]" class="tier-select">
                        <option value="T1">T1 (25 pts)</option>
                        <option value="T2" selected>T2 (40 pts)</option>
                        <option value="T3">T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="Docker">
                <div class="skill-header">
                    <input type="checkbox" id="skill_docker" name="quest_skills[]" value="Docker">
                    <label for="skill_docker" class="skill-name">Docker</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[Docker]" class="tier-select">
                        <option value="T1" selected>T1 (25 pts)</option>
                        <option value="T2">T2 (40 pts)</option>
                        <option value="T3">T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="Git">
                <div class="skill-header">
                    <input type="checkbox" id="skill_git" name="quest_skills[]" value="Git">
                    <label for="skill_git" class="skill-name">Git Version Control</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[Git]" class="tier-select">
                        <option value="T1">T1 (25 pts)</option>
                        <option value="T2" selected>T2 (40 pts)</option>
                        <option value="T3">T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="Database">
                <div class="skill-header">
                    <input type="checkbox" id="skill_database" name="quest_skills[]" value="Database">
                    <label for="skill_database" class="skill-name">Database Design</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[Database]" class="tier-select">
                        <option value="T1">T1 (25 pts)</option>
                        <option value="T2">T2 (40 pts)</option>
                        <option value="T3" selected>T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="API Development">
                <div class="skill-header">
                    <input type="checkbox" id="skill_api" name="quest_skills[]" value="API Development">
                    <label for="skill_api" class="skill-name">API Development</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[API Development]" class="tier-select">
                        <option value="T1">T1 (25 pts)</option>
                        <option value="T2">T2 (40 pts)</option>
                        <option value="T3" selected>T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="Communication">
                <div class="skill-header">
                    <input type="checkbox" id="skill_communication" name="quest_skills[]" value="Communication">
                    <label for="skill_communication" class="skill-name">Communication</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[Communication]" class="tier-select">
                        <option value="T1" selected>T1 (25 pts)</option>
                        <option value="T2">T2 (40 pts)</option>
                        <option value="T3">T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
            
            <div class="skill-item" data-skill="Problem Solving">
                <div class="skill-header">
                    <input type="checkbox" id="skill_problem_solving" name="quest_skills[]" value="Problem Solving">
                    <label for="skill_problem_solving" class="skill-name">Problem Solving</label>
                </div>
                <div class="tier-selection">
                    <label>Tier:</label>
                    <select name="skill_tiers[Problem Solving]" class="tier-select">
                        <option value="T1">T1 (25 pts)</option>
                        <option value="T2" selected>T2 (40 pts)</option>
                        <option value="T3">T3 (55 pts)</option>
                        <option value="T4">T4 (70 pts)</option>
                        <option value="T5">T5 (85 pts)</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Custom skill addition -->
        <div class="custom-skill-section">
            <h4>Add Custom Skill</h4>
            <div class="custom-skill-input">
                <input type="text" id="custom_skill_name" placeholder="Enter skill name" class="form-input">
                <select id="custom_skill_tier" class="tier-select">
                    <option value="T1">T1 (25 pts)</option>
                    <option value="T2" selected>T2 (40 pts)</option>
                    <option value="T3">T3 (55 pts)</option>
                    <option value="T4">T4 (70 pts)</option>
                    <option value="T5">T5 (85 pts)</option>
                </select>
                <button type="button" onclick="addCustomSkill()" class="btn btn-secondary">
                    <i class="fas fa-plus"></i> Add Skill
                </button>
            </div>
        </div>
        
        <!-- Selected skills preview -->
        <div class="selected-skills-preview">
            <h4>Selected Skills & Points Preview:</h4>
            <div id="skills-preview" class="skills-preview-list">
                <em>No skills selected yet</em>
            </div>
            <div class="total-points-preview">
                Total Base Points: <span id="total-base-points">0</span>
            </div>
        </div>
    </div>
</div>

<style>
.skill-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.skill-item {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 15px;
    transition: all 0.2s ease;
}

.skill-item:hover {
    border-color: #4338ca;
}

.skill-item.selected {
    border-color: #10b981;
    background: #ecfdf5;
}

.skill-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.skill-header input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

.skill-name {
    font-weight: 600;
    color: #374151;
    cursor: pointer;
}

.tier-selection {
    display: flex;
    align-items: center;
    gap: 10px;
}

.tier-selection label {
    font-size: 0.9rem;
    color: #6b7280;
}

.tier-select {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 0.9rem;
}

.custom-skill-section {
    background: #f3f4f6;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}

.custom-skill-section h4 {
    margin: 0 0 15px 0;
    color: #374151;
}

.custom-skill-input {
    display: flex;
    gap: 10px;
    align-items: center;
}

.custom-skill-input input {
    flex: 2;
}

.custom-skill-input select {
    flex: 1;
}

.selected-skills-preview {
    background: #e0f2fe;
    border: 1px solid #0284c7;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.selected-skills-preview h4 {
    margin: 0 0 15px 0;
    color: #0c4a6e;
}

.skills-preview-list {
    margin-bottom: 15px;
}

.skill-preview-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #bae6fd;
}

.skill-preview-item:last-child {
    border-bottom: none;
}

.total-points-preview {
    font-weight: bold;
    font-size: 1.1rem;
    color: #0c4a6e;
    text-align: center;
    padding-top: 10px;
    border-top: 2px solid #0284c7;
}
</style>

<script>
function updateSkillsPreview() {
    const selectedSkills = [];
    let totalPoints = 0;
    
    // Get all checked skill checkboxes
    document.querySelectorAll('input[name="quest_skills[]"]:checked').forEach(checkbox => {
        const skillName = checkbox.value;
        const tierSelect = document.querySelector(`select[name="skill_tiers[${skillName}]"]`);
        const tier = tierSelect ? tierSelect.value : 'T1';
        const points = getTierPoints(tier);
        
        selectedSkills.push({ name: skillName, tier: tier, points: points });
        totalPoints += points;
    });
    
    // Update preview
    const previewDiv = document.getElementById('skills-preview');
    const totalSpan = document.getElementById('total-base-points');
    
    if (selectedSkills.length === 0) {
        previewDiv.innerHTML = '<em>No skills selected yet</em>';
        totalSpan.textContent = '0';
    } else {
        previewDiv.innerHTML = selectedSkills.map(skill => 
            `<div class="skill-preview-item">
                <span>${skill.name}</span>
                <span>${skill.tier} (${skill.points} pts)</span>
            </div>`
        ).join('');
        totalSpan.textContent = totalPoints;
    }
}

function getTierPoints(tier) {
    const tierPoints = {
        'T1': 25,
        'T2': 40,
        'T3': 55,
        'T4': 70,
        'T5': 85
    };
    return tierPoints[tier] || 25;
}

function addCustomSkill() {
    const skillName = document.getElementById('custom_skill_name').value.trim();
    const skillTier = document.getElementById('custom_skill_tier').value;
    
    if (!skillName) {
        alert('Please enter a skill name');
        return;
    }
    
    // Check if skill already exists
    if (document.querySelector(`input[value="${skillName}"]`)) {
        alert('This skill already exists');
        return;
    }
    
    // Create new skill item
    const skillsGrid = document.querySelector('.skill-selection-grid');
    const skillId = 'skill_' + skillName.replace(/\s+/g, '_').toLowerCase();
    
    const skillItem = document.createElement('div');
    skillItem.className = 'skill-item custom-skill';
    skillItem.setAttribute('data-skill', skillName);
    skillItem.innerHTML = `
        <div class="skill-header">
            <input type="checkbox" id="${skillId}" name="quest_skills[]" value="${skillName}">
            <label for="${skillId}" class="skill-name">${skillName}</label>
            <button type="button" onclick="removeCustomSkill(this)" class="btn-remove-skill" title="Remove skill">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="tier-selection">
            <label>Tier:</label>
            <select name="skill_tiers[${skillName}]" class="tier-select">
                <option value="T1" ${skillTier === 'T1' ? 'selected' : ''}>T1 (25 pts)</option>
                <option value="T2" ${skillTier === 'T2' ? 'selected' : ''}>T2 (40 pts)</option>
                <option value="T3" ${skillTier === 'T3' ? 'selected' : ''}>T3 (55 pts)</option>
                <option value="T4" ${skillTier === 'T4' ? 'selected' : ''}>T4 (70 pts)</option>
                <option value="T5" ${skillTier === 'T5' ? 'selected' : ''}>T5 (85 pts)</option>
            </select>
        </div>
    `;
    
    skillsGrid.appendChild(skillItem);
    
    // Add event listeners
    skillItem.querySelector('input[type="checkbox"]').addEventListener('change', updateSkillsPreview);
    skillItem.querySelector('select').addEventListener('change', updateSkillsPreview);
    
    // Clear input
    document.getElementById('custom_skill_name').value = '';
    document.getElementById('custom_skill_tier').value = 'T2';
}

function removeCustomSkill(button) {
    button.closest('.skill-item').remove();
    updateSkillsPreview();
}

// Add event listeners to existing skills
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="quest_skills[]"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            this.closest('.skill-item').classList.toggle('selected', this.checked);
            updateSkillsPreview();
        });
    });
    
    document.querySelectorAll('.tier-select').forEach(select => {
        select.addEventListener('change', updateSkillsPreview);
    });
});
</script>

<style>
.btn-remove-skill {
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    font-size: 12px;
    cursor: pointer;
    margin-left: auto;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-remove-skill:hover {
    background: #dc2626;
}

.custom-skill {
    border-color: #10b981;
}
</style>