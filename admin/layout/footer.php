  </main>
</div>
<?php foreach ((array) ($jsTrang ?? []) as $f): ?>
<script src="<?= e(BASE_URL) ?>/assets/js/admin/<?= e($f) ?>.js"></script>
<?php endforeach; ?>
</body>
</html>