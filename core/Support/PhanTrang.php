<?php
// Phân trang phía máy chủ. Mặc định 10 dòng/trang ở Client, 20 dòng/trang ở Admin.
//
// Ví dụ:
//   $p = new PhanTrang($tongSoDong, 10);          // $tongSoDong lấy từ SELECT COUNT(*)
//   $sql .= ' LIMIT ' . $p->limit() . ' OFFSET ' . $p->offset();
//   ... in danh sách ...
//   echo $p->links();                              // giữ nguyên từ khóa tìm kiếm và bộ lọc
class PhanTrang
{
    private int $tong;
    private int $moiTrang;
    private int $trang;
    private int $soTrang;
    private string $thamSo;

    public function __construct(int $tongSoDong, int $moiTrang = 10, string $thamSo = 'page')
    {
        $this->tong     = max(0, $tongSoDong);
        $this->moiTrang = max(1, $moiTrang);
        $this->soTrang  = max(1, (int) ceil($this->tong / $this->moiTrang));
        $yeuCau         = (int) ($_GET[$thamSo] ?? 1);
        $this->trang    = min(max(1, $yeuCau), $this->soTrang);
        $this->thamSo   = $thamSo;
    }

    public function limit(): int    { return $this->moiTrang; }
    public function offset(): int   { return ($this->trang - 1) * $this->moiTrang; }
    public function trang(): int    { return $this->trang; }
    public function soTrang(): int  { return $this->soTrang; }
    public function tong(): int     { return $this->tong; }
    public function total(): int    { return $this->tong; }

    // Tạo URL của trang $n, giữ nguyên mọi tham số GET khác (từ khóa, bộ lọc...)
    private function url(int $n): string
    {
        $q = $_GET;
        $q[$this->thamSo] = $n;
        return '?' . http_build_query($q);
    }

    // HTML thanh phân trang (đã thoát XSS)
    public function links(): string
    {
        if ($this->soTrang <= 1) {
            return '<p class="phantrang">Tổng: ' . $this->tong . ' bản ghi</p>';
        }
        $h = '<nav class="phantrang">';
        if ($this->trang > 1) {
            $h .= '<a href="' . e($this->url($this->trang - 1)) . '">&laquo; Trước</a> ';
        }
        $tu  = max(1, $this->trang - 2);
        $den = min($this->soTrang, $this->trang + 2);
        for ($i = $tu; $i <= $den; $i++) {
            $h .= $i === $this->trang
                ? '<strong>' . $i . '</strong> '
                : '<a href="' . e($this->url($i)) . '">' . $i . '</a> ';
        }
        if ($this->trang < $this->soTrang) {
            $h .= '<a href="' . e($this->url($this->trang + 1)) . '">Sau &raquo;</a>';
        }
        $h .= '</nav>';
        return $h;
    }
}

// Tương thích ngược: Định danh Paginator trỏ tới PhanTrang
if (!class_exists('Paginator', false)) {
    class_alias('PhanTrang', 'Paginator');
}
