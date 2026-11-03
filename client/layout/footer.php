</main>
<footer class="container chan-trang">
  Đấu Trường Tri Thức - website thi đấu trắc nghiệm 1 vs 1, xếp hạng Elo theo môn.
</footer>
<?php foreach ((array) ($jsTrang ?? []) as $f): ?>
<script src="<?= e(BASE_URL) ?>/assets/js/client/<?= e($f) ?>.js"></script>
<?php endforeach; ?>
</body>
</html>