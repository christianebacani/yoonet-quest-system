<?php
// Ensure $sample_output_url is available if set in parent scope
global $sample_output_url;
// This file is a verbatim copy of the form UI used by create_quest.php.
// It expects the following variables to be present when included:
// - $mode: 'create' or 'view'
// - $employees, $skills, $quest_types
// - all the form variables used in create_quest.php such as $title, $description, $client_name, etc.
// When $mode === 'view', a small script at the end will disable all form controls to make the UI read-only.
?>

<form method="post" class="space-y-6" enctype="multipart/form-data">
    <!-- Basic Information Section -->
    <div class="card p-6">
    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
            <i class="fas fa-info-circle text-indigo-500 mr-2"></i> Basic Information
        </h2>
        
        <div class="grid grid-cols-1 gap-6">
            <!-- Quest Type Selection -->
            <div>
                <label for="display_type" class="block text-sm font-medium text-gray-700 mb-1">Quest Type*</label>
                <?php
                // Determine human-friendly name for current display_type
                $display_type_name = null;
                if (!empty($quest_types) && isset($display_type)) {
                    foreach ($quest_types as $qt) {
                        if (isset($qt['type_key']) && $qt['type_key'] === $display_type) { $display_type_name = $qt['name']; break; }
                    }
                }
                // If not found, try to lookup from DB directly (robust fallback)
                if ($display_type_name === null && isset($display_type)) {
                    try {
                        if (isset($pdo)) {
                            $stmt = $pdo->prepare("SELECT name FROM quest_types WHERE type_key = ? LIMIT 1");
                            $stmt->execute([$display_type]);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($row && !empty($row['name'])) { $display_type_name = $row['name']; }
                        }
                    } catch (Exception $e) {
                        // ignore DB errors and fall back to raw key
                    }
                }
                ?>
                <?php if ($mode === 'view'): ?>
                    <div class="w-full px-4 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-700"><?php echo htmlspecialchars($display_type_display ?? $display_type_name ?? $display_type); ?></div>
                <?php else: ?>
                    <select name="display_type" id="display_type" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300">
                        <?php foreach ($quest_types as $qt): ?>
                            <option value="<?php echo htmlspecialchars($qt['type_key']); ?>" <?php echo (isset($display_type) && $display_type == $qt['type_key']) ? 'selected' : (!isset($display_type) && $qt['type_key']=='custom' ? 'selected' : ''); ?>><?php echo htmlspecialchars($qt['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <p class="text-xs text-gray-500 mt-1">Choose the quest type. Selecting <strong>Client Call Handling</strong> will auto-attach required skills and reveal aggregation and call log fields for call center or client support tasks.</p>
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Quest Title*</label>
                <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title ?? ''); ?>" 
                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                       placeholder="Enter quest title" required maxlength="255">
                <p class="text-xs text-gray-500 mt-1">Keep the title short and descriptive. Use client/ticket code if needed, but avoid internal-only jargon.</p>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description*</label>
                <textarea id="description" name="description" rows="4"
                          class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                          placeholder="Describe the quest requirements and objectives" required maxlength="2000"><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Provide clear acceptance criteria, expected outputs, and any steps or context reviewers need. Attach sample files if helpful.</p>
            </div>

            <!-- Client / Support extra details (moved below title & description) -->
            <div id="clientDetails" style="display: <?php echo (isset($display_type) && $display_type === 'client_call') ? 'block' : 'none'; ?>;"> 
                <?php if ($mode === 'view' && !empty($sample_output_url)): ?>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-indigo-700 mb-1"><i class="fas fa-file-arrow-down mr-1"></i>Expected Output</label>
                    <div class="w-full px-4 py-2.5 border border-indigo-200 rounded-lg bg-indigo-50 text-indigo-900 mb-2 flex items-center gap-3">
                        <a href="<?php echo htmlspecialchars($sample_output_url); ?>" download class="inline-block bg-indigo-600 text-white px-3 py-1 rounded-md text-sm">Download Expected Output</a>
                        <a href="<?php echo htmlspecialchars($sample_output_url); ?>" target="_blank" class="inline-block text-indigo-700 underline font-medium text-sm">View</a>
                    </div>
                </div>
                <?php endif; ?>
                <p class="text-xs text-gray-500 mb-2">
                    <strong>Aggregate Client Call Handling:</strong> This quest covers all client calls handled for a specific date and shift or full day.<br>
                    <em>Example title:</em> "Client Call Handling for 2026-02-16 (Full Day)"<br>
                    <em>Attach a call log, ticket summary, or report as evidence.</em>
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <div class="w-full px-4 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-700"><?php echo htmlspecialchars($aggregation_date ?? ''); ?></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Shift</label>
                        <div class="w-full px-4 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
                            <?php 
                            $shift = $aggregation_shift ?? '';
                            $shiftLabels = ['full_day'=>'Full Day','morning'=>'Morning','afternoon'=>'Afternoon','evening'=>'Evening','night'=>'Night'];
                            echo htmlspecialchars($shiftLabels[$shift] ?? $shift);
                            ?>
                        </div>
                    </div>
                </div>
                <!-- Expected Output Upload for all viewers in view mode -->
                <?php if ($mode !== 'view'): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Expected Output Upload (for submitters)</label>
                    <input type="file" name="sample_output" id="sample_output" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg bg-white text-gray-700">
                    <p class="text-xs text-gray-500 mt-1">Attach a sample output file to show submitters what is expected. (Optional, visible to quest takers)</p>
                </div>
                <?php endif; ?>
                <?php /* Expected Output view block moved above Date/Shift so it appears below Description and above Date */ ?>
                <?php
                // Only show these fields if not in view mode, or if not a client_call quest
                $hide_extra = ($mode === 'view' && isset($display_type) && $display_type === 'client_call');
                ?>
                <?php if (!$hide_extra): ?>
                <div>
                    <label for="client_name" class="block text-sm font-medium text-gray-700 mb-1">Client Name</label>
                    <input type="text" id="client_name" name="client_name" value="<?php echo htmlspecialchars($client_name ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="Client or account name" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                </div>
                <div>
                    <label for="client_reference" class="block text-sm font-medium text-gray-700 mb-1">Ticket / Reference ID</label>
                    <input type="text" id="client_reference" name="client_reference" value="<?php echo htmlspecialchars($client_reference ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="Ticket number or reference" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label for="sla_priority" class="block text-sm font-medium text-gray-700 mb-1">SLA Priority</label>
                        <select id="sla_priority" name="sla_priority" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" <?php echo ($mode === 'view') ? 'disabled' : ''; ?>>
                            <option value="low" <?php echo (isset($sla_priority) && $sla_priority == 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo (isset($sla_priority) && $sla_priority == 'medium') ? 'selected' : (!isset($sla_priority) ? 'selected' : ''); ?>>Medium</option>
                            <option value="high" <?php echo (isset($sla_priority) && $sla_priority == 'high') ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                    <div>
                        <label for="expected_response" class="block text-sm font-medium text-gray-700 mb-1">Expected Response</label>
                        <input type="text" id="expected_response" name="expected_response" value="<?php echo htmlspecialchars($expected_response ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="e.g., 24 hours" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                    </div>
                </div>
                <?php endif; ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                    <div>
                        <label for="client_contact_email" class="block text-sm font-medium text-gray-700 mb-1">Client Contact Email</label>
                        <input type="email" id="client_contact_email" name="client_contact_email" value="<?php echo htmlspecialchars($client_contact_email ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="name@client.com" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                    </div>
                    <div>
                        <label for="client_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Client Contact Phone</label>
                        <input type="text" id="client_contact_phone" name="client_contact_phone" value="<?php echo htmlspecialchars($client_contact_phone ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="+1 555 000 0000" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
                    <div>
                        <label for="sla_due_hours" class="block text-sm font-medium text-gray-700 mb-1">SLA Due (hours)</label>
                        <input type="number" id="sla_due_hours" name="sla_due_hours" min="0" step="1" value="<?php echo htmlspecialchars($sla_due_hours ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="24" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                    </div>
                    <div>
                        <label for="estimated_hours" class="block text-sm font-medium text-gray-700 mb-1">Estimated Effort (hrs)</label>
                        <input type="number" id="estimated_hours" name="estimated_hours" min="0" step="0.25" value="<?php echo htmlspecialchars($estimated_hours ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="e.g., 3.5" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                    </div>
                    <div>
                        <label for="vendor_name" class="block text-sm font-medium text-gray-700 mb-1">Vendor / Provider</label>
                        <input type="text" id="vendor_name" name="vendor_name" value="<?php echo htmlspecialchars($vendor_name ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="Vendor or supplier name" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                    </div>
                </div>
                <div class="mt-4">
                    <label for="external_ticket_link" class="block text-sm font-medium text-gray-700 mb-1">External Ticket Link</label>
                    <input type="url" id="external_ticket_link" name="external_ticket_link" value="<?php echo htmlspecialchars($external_ticket_link ?? ''); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="https://support.example.com/ticket/12345" <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>>
                </div>
                <div class="mt-4">
                    <label for="service_level_description" class="block text-sm font-medium text-gray-700 mb-1">Service Level / Notes</label>
                    <textarea id="service_level_description" name="service_level_description" rows="3" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="Optional notes about expected service levels, escalation path, attachments, etc." <?php echo ($mode === 'view') ? 'readonly disabled' : ''; ?>><?php echo htmlspecialchars($service_level_description ?? ''); ?></textarea>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="quest_assignment_type" class="block text-sm font-medium text-gray-700 mb-1">Assignment Type*</label>
                    <select name="quest_assignment_type" id="quest_assignment_type" 
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300" required>
                        <option value="optional" <?php echo (isset($quest_assignment_type) && $quest_assignment_type == 'optional') ? 'selected' : ''; ?>>
                            📋 Optional - Users can choose to accept or decline
                        </option>
                        <option value="mandatory" <?php echo (isset($quest_assignment_type) && $quest_assignment_type == 'mandatory') ? 'selected' : ''; ?>>
                            ⚡ Mandatory - Automatically assigned to users
                        </option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <span class="font-medium">Optional:</span> Users can accept/decline. 
                        <span class="font-medium">Mandatory:</span> Automatically starts for assigned users.
                    </p>
                </div>
                <div>
                    <label for="due_date" class="block text-sm font-medium text-gray-700 mb-1">Due Date &amp; Time (Optional)</label>
                    <div class="relative">
                        <!-- Date/Time Display Button -->
                        <button type="button" 
                                id="dueDateBtn"
                                class="w-full px-4 py-2.5 border border-indigo-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 shadow-sm transition duration-200 bg-white hover:bg-gray-50 text-left flex items-center justify-between"
                                onclick="toggleCalendarBox()">
                            <span id="dueDateDisplay" class="text-gray-500">
                                <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>
                                <?php if (!empty($due_date)): ?>
                                    <?php echo htmlspecialchars($due_date); ?>
                                <?php else: ?>
                                    Click to select due date and time
                                <?php endif; ?>
                            </span>
                            <i class="fas fa-chevron-down text-gray-400" id="chevronIcon"></i>
                        </button>
                        <input type="hidden" id="due_date" name="due_date" value="<?php echo htmlspecialchars($due_date ?? ''); ?>">
                        
                        <!-- Calendar Box (Initially Hidden) -->
                        <div id="calendarBox" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-xl z-50 mt-1 hidden">
                            <div class="p-4">
                                <!-- Calendar Container -->
                                <div id="calendarContainer" class="mb-4"></div>
                                
                                <!-- Time Selection -->
                                <div class="border-t pt-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <label class="text-sm font-medium text-gray-700">Select Time</label>
                                        <button type="button" 
                                                onclick="clearDueDate()" 
                                                class="text-xs text-red-600 hover:text-red-800 flex items-center">
                                            <i class="fas fa-times mr-1"></i>Clear
                                        </button>
                                    </div>
                                    
                                    <div class="grid grid-cols-3 gap-2 mb-3">
                                        <div>
                                            <input type="number" 
                                                   id="hourSelect" 
                                                   min="1" 
                                                   max="12" 
                                                   value="12"
                                                   oninput="clearFieldError(this)"
                                                   class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-center">
                                            <label class="text-xs text-gray-500 mt-1 block text-center">Hour</label>
                                        </div>
                                        <div>
                                            <input type="number" 
                                                   id="minuteSelect" 
                                                   min="0" 
                                                   max="59" 
                                                   value="00"
                                                   oninput="clearFieldError(this)"
                                                   class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-center">
                                            <label class="text-xs text-gray-500 mt-1 block text-center">Min</label>
                                        </div>
                                        <div>
                                            <select id="ampmSelect" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                                <option value="AM">AM</option>
                                                <option value="PM">PM</option>
                                            </select>
                                            <label class="text-xs text-gray-500 mt-1 block text-center">AM/PM</label>
                                        </div>
                                    </div>
                                    
                                    <!-- Quick Time Buttons -->
                                    <div class="flex flex-wrap gap-1 mb-3">
                                        <button type="button" onclick="setQuickTime('09:00 AM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">9 AM</button>
                                        <button type="button" onclick="setQuickTime('12:00 PM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">12 PM</button>
                                        <button type="button" onclick="setQuickTime('05:00 PM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">5 PM</button>
                                        <button type="button" onclick="setQuickTime('11:59 PM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">End of Day</button>
                                    </div>
                                    
                                    <!-- Apply Button -->
                                    <button type="button" 
                                            onclick="applyDateTime()" 
                                            class="w-full px-3 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition-colors">
                                        Apply Date & Time
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <p class="mt-1 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Click the button above to select both date and time for quest deadline
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Reward & Settings Section -->
    <div class="card p-6 mt-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
            <i class="fas fa-gem text-indigo-500 mr-2"></i> Reward & Settings
        </h2>
        
        <div class="grid grid-cols-1 gap-6">
            <!-- Quest Skills Selection -->
            <div id="skillsSection">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-gray-700">Required Skills* (Select or Add 1-5)</label>
                    <div class="text-sm text-gray-600">
                        <span id="skill-counter" class="font-semibold text-indigo-600"><?php echo isset($quest_skills_count) ? intval($quest_skills_count) : '0'; ?></span>/5 selected
                    </div>
                </div>
                
                <!-- Selected Skills Display -->
                <div id="selected-skills-display" class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg" style="min-height:80px;">
                    <div class="text-xs font-medium text-blue-800 mb-1">Selected Skills:</div>
                    <div id="selected-skills-badges" class="flex flex-wrap gap-2">
                        <?php if (!empty($quest_skills) && is_array($quest_skills)): ?>
                            <?php foreach ($quest_skills as $qs): ?>
                                <?php
                                    $sname = htmlspecialchars($qs['skill_name'] ?? ($qs['skill_id'] ?? 'Skill'));
                                    // Determine tier numeric value from possible schema variations
                                    $tier = null;
                                    if (isset($qs['tier_level'])) {
                                        $tier = intval($qs['tier_level']);
                                    } elseif (!empty($qs['required_level'])) {
                                        $req = strtolower(trim($qs['required_level']));
                                        $reqMap = ['beginner'=>1, 'intermediate'=>2, 'advanced'=>3, 'expert'=>4, 'master'=>5];
                                        $tier = $reqMap[$req] ?? null;
                                    } elseif (isset($qs['base_points'])) {
                                        $bp = intval($qs['base_points']);
                                        if ($bp <= 3) { $tier = 1; }
                                        elseif ($bp <= 8) { $tier = 2; }
                                        elseif ($bp <= 18) { $tier = 3; }
                                        elseif ($bp <= 30) { $tier = 4; }
                                        else { $tier = 5; }
                                    }
                                    $tierNames = [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Advanced', 4 => 'Expert', 5 => 'Master'];
                                    $tierLabel = $tier ? ($tierNames[$tier] ?? 'Tier ' . $tier) : '—';
                                    $cat = htmlspecialchars($qs['category_name'] ?? ($qs['category'] ?? 'Auto'));
                                    // Show (Auto) only for client_call quests, otherwise (Manual)
                                    $auto = (isset($display_type) && $display_type === 'client_call') ? 'Auto' : 'Manual';
                                ?>
                                <div class="px-3 py-1 rounded-lg bg-white border border-blue-200 text-sm flex items-center gap-2" title="<?php echo $tierLabel . ' • ' . $cat; ?>">
                                    <span class="font-medium text-blue-700"><?php echo $sname; ?></span>
                                    <span class="ml-2 text-xs bg-yellow-50 text-yellow-700 px-2 py-0.5 rounded">(<?php echo $auto; ?>)</span>
                                    <span class="ml-2 text-xs font-bold text-gray-900 bg-yellow-100 px-2 py-0.5 rounded"><?php echo $tierLabel; ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-xs text-blue-600 italic">No skills selected yet</div>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="text-xs text-gray-500">Tip: For client-facing quests, keep skills focused on customer handling and diagnosis. Auto-attached skills will be added if you choose the Client & Support type.</p>

                <!-- Category Buttons (visual only in view mode) -->
                <div class="mb-4">
                    <div class="flex flex-wrap gap-2">
                        <button type="button" 
                                onclick="showCategorySkills('technical', 'Technical Skills')" 
                                class="skill-category-btn px-4 py-2 bg-blue-100 hover:bg-blue-200 text-blue-800 rounded-lg border border-blue-300 transition-colors flex items-center">
                            <i class="fas fa-code mr-2"></i>Technical Skills
                        </button>
                        <button type="button" 
                                onclick="showCategorySkills('communication', 'Communication Skills')" 
                                class="skill-category-btn px-4 py-2 bg-amber-100 hover:bg-amber-200 text-amber-800 rounded-lg border border-amber-300 transition-colors flex items-center">
                            <i class="fas fa-comments mr-2"></i>Communication Skills
                        </button>
                        <button type="button" 
                                onclick="showCategorySkills('soft', 'Soft Skills')" 
                                class="skill-category-btn px-4 py-2 bg-rose-100 hover:bg-rose-200 text-rose-800 rounded-lg border border-rose-300 transition-colors flex items-center">
                            <i class="fas fa-heart mr-2"></i>Soft Skills
                        </button>
                        <button type="button" 
                                onclick="showCategorySkills('business', 'Business Skills')" 
                                class="skill-category-btn px-4 py-2 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 rounded-lg border border-emerald-300 transition-colors flex items-center">
                            <i class="fas fa-briefcase mr-2"></i>Business Skills
                        </button>
                    </div>
                </div>

                <!-- The modals and JS-driven skill selection UI are intentionally included to match create_quest.php. -->

            </div>
        </div>
    </div>

    <!-- Assignment Section (partial - show employees list like create_quest) -->
    <?php
    // Only show the assignment UI when in create/edit mode, or when viewing as the quest creator.
    // For viewers who are not the creator (e.g. submitters), we hide this section to avoid confusion.
    $show_assignment_section = true;
    if (isset($mode) && $mode === 'view') {
        // If $is_creator is not set or false, don't show the assignment section in view mode
        if (empty($is_creator) || !$is_creator) {
            $show_assignment_section = false;
        }
    }
    if ($show_assignment_section):
    ?>
    <?php if (isset($mode) && $mode === 'view'): ?>
    <div class="w-full mt-6">
        <div class="card p-6">
    <?php else: ?>
    <div class="max-w-2xl mx-auto mt-6">
        <div class="card p-6">
    <?php endif; ?>
            <h2 class="text-xl font-semibold text-gray-800 mb-3 pb-2 border-b border-gray-100">
                <i class="fas fa-users text-indigo-500 mr-2"></i> Assignment
            </h2>

            <div>
                <div class="flex gap-4 border-b border-gray-200 pb-2 mb-3">
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" name="assignment_type" value="individual" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500" checked onchange="toggleAssignmentType()">
                        <span class="ml-2 text-sm font-medium text-gray-700"><i class="fas fa-user mr-1"></i>Individuals</span>
                    </label>
                </div>

                <div id="individualAssignment">
                    <div class="mb-3">
                        <div class="relative">
                            <input type="text" 
                                   id="employeeSearch" 
                                   placeholder="Search employees by name or ID..." 
                                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                                   oninput="filterEmployees()">
                            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                            <button type="button" 
                                    onclick="clearEmployeeSearch()" 
                                    class="absolute right-3 top-3 text-gray-400 hover:text-gray-600"
                                    id="clearSearchBtn" 
                                    style="display: none;">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </div>
                    </div>

                    <div id="selectedEmployeesDisplay" class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg min-h-[40px]">
                        <div class="text-xs font-medium text-blue-800 mb-1">Selected Employees:</div>
                        <div id="selectedEmployeesBadges" class="flex flex-wrap gap-2">
                            <div class="text-xs text-blue-600 italic">No employees selected</div>
                        </div>
                    </div>

                    <div class="border border-gray-200 rounded-lg max-h-40 overflow-y-auto bg-white">
                        <div id="employeeList">
                            <?php foreach ($employees as $employee): ?>
                                <?php
                                    // Skip the creator from the assignment list
                                    if (isset($_SESSION['employee_id']) && $employee['employee_id'] === $_SESSION['employee_id']) {
                                        continue;
                                    }
                                ?>
                                <?php
                                    $data_name = strtolower(trim($employee['full_name'] ?? ''));
                                    $data_id_attr = strtolower(trim($employee['employee_id'] ?? ''));
                                    $emp_id = $employee['employee_id'];
                                    $user_id_stmt = $pdo->prepare('SELECT id, last_name, first_name, middle_name, full_name FROM users WHERE employee_id = ? LIMIT 1');
                                    $user_id_stmt->execute([$emp_id]);
                                    $user_row = $user_id_stmt->fetch(PDO::FETCH_ASSOC);
                                    $profile_user_id = $user_row ? $user_row['id'] : '';
                                    $display_name = $user_row ? format_display_name($user_row) : format_display_name(['full_name' => $employee['full_name']]);
                                ?>
                                <label class="employee-item flex items-center p-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0" 
                                    data-name="<?php echo htmlspecialchars($data_name); ?>"
                                    data-id="<?php echo htmlspecialchars($data_id_attr); ?>">
                                 <input type="checkbox" 
                                     name="assign_to[]" 
                                     value="<?php echo $employee['employee_id']; ?>"
                                     class="employee-checkbox h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                     data-name="<?php echo htmlspecialchars($display_name); ?>"
                                     data-employee-id="<?php echo htmlspecialchars($employee['employee_id']); ?>"
                                     data-user-id="<?php echo htmlspecialchars($profile_user_id); ?>"
                                     onchange="handleEmployeeSelection(this)"
                                     <?php echo in_array($employee['employee_id'], $assign_to ?? []) ? 'checked' : ''; ?>>
                                        <div class="ml-2 flex-1">
                                            <a class="text-sm font-medium text-indigo-700 hover:underline" href="profile_view.php?user_id=<?php echo urlencode($profile_user_id); ?>">
                                                <?php echo htmlspecialchars($display_name); ?>
                                            </a>
                                            <div class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></div>
                                        </div>
                                    </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Search and select employees to assign this quest. You cannot assign quests to yourself.
                    </p>
                </div>

            </div>
        </div>
    </div>

        <?php endif; // end show_assignment_section ?>

        <!-- Submit Button area (we'll keep button visible but in view mode we'll disable it via JS) -->
    <?php if (!isset($mode) || $mode !== 'view'): ?>
    <div class="mt-8 pt-6 border-t border-gray-100">
        <div class="flex justify-center">
            <button type="submit" class="btn-primary px-8 py-3 rounded-lg font-medium shadow-lg hover:shadow-xl transition-shadow duration-200 flex items-center">
                <i class="fas fa-plus-circle mr-2"></i> Create Quest
            </button>
        </div>
    </div>
    <?php endif; ?>
</form>

<?php if ($mode === 'view'): ?>
<script>
// Disable interactive elements to make this form read-only while keeping layout and buttons visible.
document.addEventListener('DOMContentLoaded', function(){
    const form = document.querySelector('form');
    if (!form) return;
    // Disable inputs, selects, textareas
    form.querySelectorAll('input, select, textarea, button').forEach(function(el){
        // Keep the submit button visible but disabled (already handled by server-side attribute)
        el.disabled = true;
    });
    // Hide modal triggers that would open editing dialogs (optional)
    document.querySelectorAll('.skill-category-btn, #addSubtask, #saveCustomRecurrence').forEach(function(b){ b.style.pointerEvents = 'none'; });
});
</script>
<?php endif; ?>
