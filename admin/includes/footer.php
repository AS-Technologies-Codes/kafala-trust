<?php
// Only loaded after includes/header.php.
if (!defined('KT_LAYOUT')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
        <p class="text-center small text-secondary mt-4 mb-0">
            &copy; <?= e(date('Y')) ?> Kifala Trust. All rights reserved.
        </p>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<?php
// Optional: a page can set $extraScripts = '<script>...</script>'; before including the footer.
if (!empty($extraScripts)) {
    echo $extraScripts;
}
?>
</body>
</html>