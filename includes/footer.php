<?php
// includes/footer.php
?>
    </main> </div> </div> <script>
    // Vanilla JS Sidebar Toggle
    function toggleSidebar() {
        const sidebar = document.getElementById('app-sidebar');
        sidebar.classList.toggle('collapsed');
    }

    // Example Toast Notification Function (Available globally)
    function showToast(message, type = 'success') {
        // Implementation for later modules
        console.log(`[${type.toUpperCase()}] ${message}`);
    }
</script>
</body>
</html>