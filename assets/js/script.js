// You can add any client-side functionality here
// (AJAX quest action logic is now in open_quests.php inline script)
document.addEventListener('DOMContentLoaded', function() {
    // Add any interactive elements here
    // Fix: Ensure anchor tags with .interactive-button are not prevented from navigating
    document.querySelectorAll('a.interactive-button').forEach(function(link) {
        link.addEventListener('click', function(e) {
            // Only prevent default if href is '#', otherwise allow navigation
            if (link.getAttribute('href') === '#') {
                e.preventDefault();
            }
        });
    });
    
    // Example: Form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Add any client-side validation here
            const password = form.querySelector('#password');
            const confirmPassword = form.querySelector('#confirm_password');
            
            if (password && confirmPassword && password.value !== confirmPassword.value) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    });
    
    // Recurrence pattern button visual feedback
    let lastCustomRecurrence = '';
    const customRecurrenceData = document.getElementById('customRecurrenceData');
    const customRecurrenceDataHidden = document.getElementById('customRecurrenceDataHidden');
    // On page load, initialize lastCustomRecurrence from hidden input or textarea
    if (customRecurrenceData && customRecurrenceData.value) {
        lastCustomRecurrence = customRecurrenceData.value;
    } else if (customRecurrenceDataHidden && customRecurrenceDataHidden.value) {
        lastCustomRecurrence = customRecurrenceDataHidden.value;
    }

    document.querySelectorAll('.recurrence-pattern').forEach(function(label) {
        label.addEventListener('click', function() {
            document.querySelectorAll('.recurrence-pattern').forEach(function(l) {
                l.classList.remove('selected');
                l.style.background = '#fff';
                l.style.color = '#374151';
                l.style.borderColor = '#e5e7eb';
            });
            this.classList.add('selected');
            this.style.background = '#eef2ff';
            this.style.color = '#4338ca';
            this.style.borderColor = '#4338ca';
            // Set the radio input as checked
            var radio = this.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            // Show/hide custom recurrence settings
            if (radio && radio.value === 'custom') {
                document.getElementById('customRecurrenceSettings').style.display = 'block';
                // Only restore if switching from a non-custom pattern
                if (customRecurrenceData && lastCustomRecurrence !== customRecurrenceData.value) {
                    customRecurrenceData.value = lastCustomRecurrence;
                }
                if (customRecurrenceDataHidden && lastCustomRecurrence !== customRecurrenceDataHidden.value) {
                    customRecurrenceDataHidden.value = lastCustomRecurrence;
                }
            } else {
                document.getElementById('customRecurrenceSettings').style.display = 'none';
                // Only update lastCustomRecurrence if switching away from custom
                if (customRecurrenceData && document.querySelector('input[name="recurrence_pattern"][value="custom"]').checked) {
                    lastCustomRecurrence = customRecurrenceData.value;
                }
                if (customRecurrenceDataHidden && document.querySelector('input[name="recurrence_pattern"][value="custom"]').checked) {
                    customRecurrenceDataHidden.value = lastCustomRecurrence;
                }
            }
        });
    });

    // Sync custom recurrence textarea to memory and hidden input for saving
    if (customRecurrenceData && customRecurrenceDataHidden) {
        customRecurrenceData.addEventListener('input', function() {
            lastCustomRecurrence = customRecurrenceData.value;
            customRecurrenceDataHidden.value = customRecurrenceData.value;
        });
        // On page load, restore textarea from memory if present
        if (lastCustomRecurrence) {
            customRecurrenceData.value = lastCustomRecurrence;
            customRecurrenceDataHidden.value = lastCustomRecurrence;
        }
    }
});

function confirmDeleteQuest() {
    return confirm('Are you sure you want to delete this quest? This action cannot be undone.');
}
