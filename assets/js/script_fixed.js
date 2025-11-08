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

// --- Audio feedback helpers -------------------------------------------------
// Play short, distinctive sounds for Accept and Decline actions using WebAudio
// These functions are designed to be lightweight and not require external assets.
function _getAudioContext() {
    if (window._yoonetAudioCtx) return window._yoonetAudioCtx;
    try {
        const C = window.AudioContext || window.webkitAudioContext;
        window._yoonetAudioCtx = new C();
        return window._yoonetAudioCtx;
    } catch (e) {
        return null;
    }
}

function _playTone(freq, when = 0, dur = 0.12, type = 'sine', volume = 0.12) {
    const ctx = _getAudioContext();
    if (!ctx) return;
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    o.type = type;
    o.frequency.setValueAtTime(freq, ctx.currentTime + when);
    g.gain.setValueAtTime(0.0001, ctx.currentTime + when);
    g.gain.exponentialRampToValueAtTime(Math.max(0.001, volume), ctx.currentTime + when + 0.005);
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + when + dur);
    o.connect(g); g.connect(ctx.destination);
    o.start(ctx.currentTime + when);
    o.stop(ctx.currentTime + when + dur + 0.02);
}

function playAcceptSound() {
    // Pleasant ascending triad (brief)
    const now = 0;
    _playTone(660, now + 0.00, 0.09, 'sine', 0.12);
    _playTone(880, now + 0.09, 0.08, 'sine', 0.10);
    _playTone(990, now + 0.18, 0.10, 'sine', 0.09);
}

function playDeclineSound() {
    // Short, lower descending minor-ish pattern for decline
    const now = 0;
    _playTone(300, now + 0.00, 0.12, 'sawtooth', 0.11);
    _playTone(260, now + 0.12, 0.11, 'sawtooth', 0.10);
    _playTone(220, now + 0.23, 0.14, 'sine', 0.09);
}

// Try to detect already-rendered success messages on page load and play a sound
document.addEventListener('DOMContentLoaded', function() {
    try {
        // Look for common success/error containers used across pages
        const containers = document.querySelectorAll('.bg-green-50, .bg-green-100, .bg-red-50, .bg-red-100, .bg-green-200, .bg-red-200');
        containers.forEach(c => {
            const txt = (c.textContent || '').toLowerCase();
            if (txt.includes('accepted') || txt.includes('accepted!') || txt.includes("it's now in your active list") || txt.includes('quest accepted')) {
                // Play accept sound (wrapped in try to avoid throwing if audio blocked)
                try { playAcceptSound(); } catch (e) { /* ignore */ }
            } else if (txt.includes('declined') || txt.includes('quest declined')) {
                try { playDeclineSound(); } catch (e) { /* ignore */ }
            }
        });
    } catch (e) { /* no-op */ }
});
