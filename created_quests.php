<?php
// created_quests.php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

$page_title = 'My Created Quests';
include 'includes/header.php';
?>
<main class="max-w-4xl mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold mb-6 text-indigo-800">My Created Quests</h1>
    <p class="mb-6 text-gray-600">See all quests you have created for others to complete.</p>
    <!-- TODO: List created quests in a modern, clean card layout -->
    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-gray-500">This page will show all quests you have created. (Module coming soon.)</p>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
