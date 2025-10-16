<?php
// submitted_quests.php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

$page_title = 'Submitted Quests';
include 'includes/header.php';
?>
<main class="max-w-4xl mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold mb-6 text-orange-700">Submitted Quests</h1>
    <p class="mb-6 text-gray-600">Review all quests you have submitted for grading or review.</p>
    <!-- TODO: List submitted quests in a modern, clean card layout -->
    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-gray-500">This page will show all your submitted quests. (Module coming soon.)</p>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
