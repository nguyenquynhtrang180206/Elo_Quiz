<?php if (!empty($giaoDienMoi)): ?>
    </main>
  </div>
</div>
<?php
  // Chuông thông báo ở thanh trên cần thongbao_admin.js trên mọi trang dùng khung mới (trang Tổng quan đã tự nạp)
  $jsNap = (array) ($jsTrang ?? []);
  if (!in_array('thongbao_admin', $jsNap, true)) { $jsNap[] = 'thongbao_admin'; }
  foreach ($jsNap as $f): ?>
<script src="<?= e(BASE_URL) ?>/assets/js/admin/<?= e($f) ?>.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/admin/' . $f . '.js') ?>"></script>
<?php endforeach; ?>
<?php else: ?>
  </main>
</div>
<?php foreach ((array) ($jsTrang ?? []) as $f): ?>
<script src="<?= e(BASE_URL) ?>/assets/js/admin/<?= e($f) ?>.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/admin/' . $f . '.js') ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>