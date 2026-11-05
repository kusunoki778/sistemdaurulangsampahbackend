-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 22, 2025 at 04:06 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ecoliving_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id_admin` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id_admin`, `username`, `email`, `password_hash`, `created_at`) VALUES
(1, 'admin', 'admin@ecoliving.local', '$2y$10$p3ZMFFGsheCpeHwTzXj5gegQSAXatLlJCetavMK2/Mn29XEwW0J.i', '2025-12-20 08:39:26');

-- --------------------------------------------------------

--
-- Table structure for table `artikel`
--

CREATE TABLE `artikel` (
  `id` int(11) NOT NULL,
  `judul` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `deskripsi_singkat` varchar(255) NOT NULL,
  `konten_lengkap` text NOT NULL,
  `kategori` varchar(100) NOT NULL,
  `gambar` varchar(255) DEFAULT NULL,
  `tanggal_publikasi` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `artikel`
--

INSERT INTO `artikel` (`id`, `judul`, `slug`, `deskripsi_singkat`, `konten_lengkap`, `kategori`, `gambar`, `tanggal_publikasi`) VALUES
(1, 'Panduan Lengkap Memilah Sampah Organik dan Anorganik', 'panduan-lengkap-memilah-sampah-organik-dan-anorganik', 'Panduan praktis untuk membedakan sampah organik dan anorganik agar pemilahan lebih efektif dan ramah lingkungan.', 'Sampah yang tidak dipilah dengan benar dapat menyebabkan pencemaran lingkungan dan menghambat proses daur ulang. Karena itu, memilah sampah merupakan langkah dasar namun sangat penting dalam pengelolaan sampah rumah tangga. Pemilahan yang tepat membantu mengurangi volume sampah di TPA, mempercepat proses daur ulang, dan mencegah pencemaran tanah serta air.\r\n\r\nApa Itu Sampah Organik?\r\nSampah organik adalah sampah yang berasal dari sisa makhluk hidup dan dapat terurai secara alami. Contoh sampah organik meliputi daun, rumput, sisa sayuran, kulit buah, dan sisa makanan. Sampah ini dapat dimanfaatkan kembali, misalnya sebagai kompos atau pupuk alami.\r\n\r\nCara Mengelola Sampah Organik:\r\n1.Kumpulkan sampah organik pada wadah khusus.\r\n2.Pisahkan dari plastik atau bahan lain yang sulit terurai.\r\n3.Buat kompos dengan metode sederhana seperti komposter rumah atau lubang biopori.\r\n4.Gunakan kompos untuk tanaman atau taman rumah.\r\n\r\nApa Itu Sampah Anorganik?\r\nSampah anorganik adalah sampah yang sulit terurai dan biasanya berasal dari bahan buatan manusia seperti plastik, logam, kaca, dan kertas. Sampah anorganik dapat didaur ulang menjadi barang baru jika dipilah dengan benar.\r\n\r\nCara Mengelola Sampah Anorganik:\r\n1.Pisahkan berdasarkan jenis plastik, kaca, kaleng, dan kertas.\r\n2.Bersihkan sampah yang masih kotor agar mudah diproses.\r\n3.Gunakan fasilitas bank sampah atau program daur ulang.\r\n\r\nKesimpulan\r\nDengan memilah sampah organik dan anorganik di rumah, kita berkontribusi menjaga lingkungan dan mendukung ekonomi sirkular. Langkah kecil dari rumah dapat membawa dampak besar untuk bumi.', 'Pemilahan Sampah', 'assets/img/artikel/artikel_1766319688_2478.jpg', '2025-11-07'),
(2, 'Manfaat Ekonomi dari Pemilahan Sampah yang Tepat', 'manfaat-ekonomi-dari-pemilahan-sampah-yang-tepat', 'Penjelasan mengenai bagaimana pemilahan sampah dapat memberikan keuntungan ekonomi bagi masyarakat.', 'Pemilahan sampah tidak hanya berdampak positif pada lingkungan, tetapi juga memberikan manfaat ekonomi yang besar bagi masyarakat. Sampah yang dipilah dengan tepat dapat menjadi sumber pendapatan baru, bahan baku industri daur ulang, hingga mengurangi biaya operasional pengelolaan sampah.\r\n\r\n1. Meningkatkan Nilai Ekonomi Sampah\r\nDengan memilah sampah dari rumah, nilai jual bahan anorganik seperti plastik, aluminium, kardus, dan kaca menjadi lebih tinggi. Plastik PET, misalnya, memiliki harga lebih tinggi dibandingkan plastik campuran yang kotor.\r\n\r\n2. Mengurangi Beban TPA dan Biaya Pengolahan\r\nSampah yang tidak dipilah menambah volume di tempat pembuangan akhir. Pemilahan yang baik membantu pemerintah mengurangi biaya transportasi dan proses pengolahan sampah.\r\n\r\n3. Mendorong Pertumbuhan Industri Daur Ulang\r\nIndustri daur ulang sangat bergantung pada bahan baku bekas. Semakin banyak sampah terpilah, semakin besar peluang perusahaan daur ulang untuk memproduksi barang baru, membuka lapangan kerja, dan meningkatkan ekonomi lokal.\r\n\r\n4. Menciptakan Produk Bernilai Tambah\r\nSampah terpilah dapat diolah menjadi produk bernilai tinggi seperti kerajinan tangan, peralatan rumah tangga, kompos, atau material bangunan ramah lingkungan.\r\n\r\nKesimpulan\r\nPemilahan sampah yang tepat adalah investasi masa depan. Selain menjaga kelestarian lingkungan, proses ini juga membuka banyak peluang ekonomi bagi masyarakat.', 'Ekonomi Hijau', 'assets/img/artikel/artikel_1766320041_8195.jpg', '2025-12-15'),
(3, 'Bahaya Mikroplastik Bagi Ekosistem Laut', 'bahaya-mikroplastik-bagi-ekosistem-laut', 'Pembahasan mengenai ancaman mikroplastik terhadap biota laut dan manusia yang mengonsumsi hasil laut.', 'Mikroplastik adalah partikel plastik berukuran sangat kecil (kurang dari 5 mm) yang berasal dari serpihan plastik atau produk yang mengandung microbeads. Meskipun ukurannya kecil, mikroplastik membawa dampak serius terhadap ekosistem laut dan kesehatan manusia.\r\n\r\nSumber Mikroplastik:\r\n1.Pecahan plastik dari sampah yang terurai\r\n2.Produk kecantikan dan sabun yang mengandung microbeads\r\n3.Serat sintetis dari pakaian\r\n4.Ban kendaraan yang aus\r\n\r\nDampak Mikroplastik pada Biota Laut\r\nIkan, kerang, udang, dan plankton sering mengira mikroplastik sebagai makanan. Akibatnya, sistem pencernaan mereka tersumbat, menimbulkan stres, menghambat perkembangan, bahkan menyebabkan kematian.\r\n\r\nDampak pada Manusia\r\nManusia yang mengonsumsi makanan laut berpotensi terpapar mikroplastik. Penelitian menunjukkan mikroplastik dapat menyebabkan gangguan hormon, inflamasi organ, dan risiko penyakit kronis.\r\n\r\nKerusakan Ekosistem Laut\r\nMikroplastik mengganggu rantai makanan, menyebabkan perubahan keseimbangan ekosistem, dan mengancam keanekaragaman hayati.\r\n\r\nKesimpulan\r\nMengurangi penggunaan plastik sekali pakai serta mendaur ulang plastik adalah langkah efektif untuk mencegah mikroplastik memasuki ekosistem laut.', 'Isu Lingkungan', 'assets/img/artikel/artikel_1766320206_8382.jpg', '2025-11-06'),
(4, '10 Tips Mengurangi Sampah Rumah Tangga', '10-tips-mengurangi-sampah-rumah-tangga', 'Tips praktis untuk membantu mengurangi sampah sehari-hari dan menerapkan gaya hidup ramah lingkungan.', 'Sampah rumah tangga merupakan sumber utama pencemaran lingkungan. Namun, ada banyak langkah sederhana yang dapat dilakukan untuk mengurangi jumlah sampah yang dihasilkan setiap hari.\r\n\r\n1. Gunakan Tas Belanja Reusable\r\nHindari kantong plastik sekali pakai dan gunakan tas kain yang lebih tahan lama.\r\n\r\n2. Bawa Botol Minum Sendiri\r\nBotol plastik sekali pakai adalah salah satu sampah terbanyak. Gunakan tumbler untuk mengurangi limbah plastik.\r\n\r\n3. Pisahkan Sampah Organik dan Anorganik\r\nPemilahan sampah dari rumah sangat penting untuk mempermudah proses daur ulang.\r\n\r\n4. Kurangi Pembelian Produk Berkemasan\r\nPilih produk isi ulang atau tanpa kemasan berlebihan.\r\n\r\n5. Manfaatkan Sisa Makanan\r\nUbah sisa sayuran atau buah menjadi kompos atau makanan baru seperti sup atau smoothies.\r\n\r\n6. Gunakan Wadah Penyimpanan Reusable\r\nKurangi penggunaan plastik wrap dan pilih wadah kaca atau stainless.\r\n\r\n7. Donasikan Barang yang Tidak Dipakai\r\nDaripada membuang, pakaian dan barang bekas bisa diberikan kepada yang membutuhkan.\r\n\r\n8. Hindari Produk Sekali Pakai\r\nSeperti sedotan plastik, piring plastik, dan barang sekali pakai lainnya.\r\n\r\n9. Gunakan Produk Ramah Lingkungan\r\nPilih sabun, deterjen, dan produk pembersih yang ramah lingkungan.\r\n\r\n10. Daur Ulang Barang Bekas\r\nBotol kaca, kardus, plastik PET, dan aluminium memiliki nilai ekonomi jika dikumpulkan dan dijual.\r\n\r\nKesimpulan\r\nMengurangi sampah rumah tangga bukan hal sulit. Dengan kebiasaan baru yang sederhana, kita dapat berkontribusi besar terhadap kelestarian lingkungan.', 'Gaya Hidup', 'assets/img/artikel/artikel_1766320440_8964.jpg', '2025-12-09'),
(5, 'Cara Mengolah Minyak Jelantah Menjadi Sabun', 'cara-mengolah-minyak-jelantah-menjadi-sabun', 'Panduan mudah mengolah minyak jelantah bekas menjadi sabun yang aman dan ramah lingkungan.', 'Minyak jelantah adalah salah satu jenis limbah dapur yang sering dianggap tidak berguna. Padahal, minyak bekas ini dapat didaur ulang menjadi sabun yang bermanfaat dan ramah lingkungan.\r\n\r\nMengapa Minyak Jelantah Harus Didaur Ulang?\r\nMembuang minyak jelantah ke saluran air dapat menyebabkan penyumbatan pipa dan pencemaran lingkungan. Daur ulang minyak jelantah juga membantu mengurangi limbah rumah tangga.\r\n\r\nBahan-bahan yang Dibutuhkan:\r\n1.Minyak jelantah yang telah disaring\r\n2.Soda kaustik (NaOH)\r\n3.Air\r\n4.Pewangi atau esensial oil\r\n5.Cetakan sabun\r\n\r\nCara Membuat Sabun dari Minyak Jelantah:\r\n1.Saring minyak jelantah hingga bersih.\r\n2.Larutkan soda kaustik dengan air (hati-hati, bersifat panas).\r\n3.Campurkan larutan NaOH dengan minyak jelantah sedikit demi sedikit.\r\n4.Aduk hingga mengental seperti adonan.\r\n5.Tambahkan pewangi alami jika diperlukan.\r\n6.Tuang ke cetakan dan diamkan selama 24 jam.\r\n7.Keringkan sabun selama 2–3 minggu sebelum digunakan.\r\n\r\nKesimpulan\r\nMengolah minyak jelantah menjadi sabun adalah cara cerdas mengurangi limbah dan menghasilkan produk yang bermanfaat. Selain ramah lingkungan, sabun hasil daur ulang ini juga dapat dimanfaatkan untuk keperluan rumah tangga.', 'Daur Ulang', 'assets/img/artikel/artikel_1766320688_6369.jpg', '2025-11-29'),
(6, 'Mengenal Jenis-jenis Plastik untuk Daur Ulang', 'mengenal-jenis-jenis-plastik-untuk-daur-ulang', 'Penting untuk membedakan kode plastik saat memilah.', 'Plastik adalah salah satu material yang paling banyak digunakan dalam kehidupan sehari-hari. Namun, tidak semua plastik memiliki karakteristik yang sama dan tidak semuanya bisa didaur ulang dengan cara yang serupa. Karena itu, penting bagi kita untuk mengenali 7 kode resin plastik yang biasanya ditemukan pada kemasan. Kode ini membantu menentukan apakah plastik tersebut aman, dapat didaur ulang, dan bagaimana cara penanganannya.\r\n\r\n1. PETE / PET (Kode 1) – Polyethylene Terephthalate\r\nJenis plastik ini digunakan pada botol air mineral, botol jus, dan beberapa kemasan makanan. PET merupakan plastik yang paling mudah didaur ulang dan memiliki nilai ekonomi yang tinggi. Namun, plastik ini tidak direkomendasikan untuk digunakan berulang kali karena dapat melepaskan zat kimia tertentu jika dipanaskan.\r\n\r\n2. HDPE (Kode 2) – High-Density Polyethylene\r\nHDPE banyak ditemukan pada galon air, botol detergen, botol minyak, dan kotak makanan. Jenis plastik ini lebih kuat, tebal, dan tahan panas. HDPE termasuk plastik yang sangat aman dan memiliki tingkat daur ulang yang baik.\r\n\r\n3. PVC (Kode 3) – Polyvinyl Chloride\r\nPlastik jenis ini digunakan pada pipa, mainan, dan beberapa jenis kemasan. PVC mengandung bahan kimia seperti klorin yang dapat berbahaya jika tidak ditangani dengan benar. Proses daur ulangnya lebih sulit dan tidak banyak fasilitas yang mampu mengolahnya.\r\n\r\n4. LDPE (Kode 4) – Low-Density Polyethylene\r\nLDPE sering digunakan pada plastik kresek, pembungkus makanan, dan kantong belanja. Meskipun relatif aman, daur ulang LDPE masih terbatas dan nilai jualnya tidak terlalu tinggi dibandingkan jenis plastik lainnya.\r\n\r\n5. PP (Kode 5) – Polypropylene\r\nPP digunakan dalam tempat makan, sedotan, tutup botol, dan beberapa kemasan makanan. Plastik ini tahan panas dan cukup aman digunakan. PP dapat didaur ulang, meski prosesnya membutuhkan fasilitas khusus.\r\n\r\n6. PS (Kode 6) – Polystyrene\r\nPlastik jenis ini ditemukan pada styrofoam, cup makanan, dan wadah sekali pakai. PS mudah pecah dan dapat melepaskan zat berbahaya apabila terkena panas. Kemampuan daur ulangnya rendah sehingga sebaiknya dihindari.\r\n\r\n7. Other / O (Kode 7)\r\nKategori ini mencakup berbagai plastik campuran seperti PC (polycarbonate) atau bioplastik baru. Tidak semua plastik kode 7 dapat didaur ulang, sehingga harus diperiksa sesuai petunjuk pabrik atau fasilitas daur ulang.\r\n\r\nMengapa Pemilahan Berdasarkan Kode Plastik Penting?\r\nMemisahkan plastik menurut kodenya dapat meningkatkan efisiensi daur ulang dan nilai ekonominya. Plastik seperti PET dan HDPE memiliki nilai jual lebih tinggi, sementara plastik seperti PVC dan PS membutuhkan penanganan khusus. Dengan mengenali kode ini, masyarakat dapat memilah sampah dengan benar dan mendukung proses daur ulang yang lebih berkelanjutan.\r\n\r\nKesimpulan\r\nMengetahui jenis-jenis plastik adalah langkah awal yang penting dalam menjaga lingkungan. Dengan memilah plastik berdasarkan kode resin, kita dapat membantu mengurangi pencemaran, meningkatkan nilai daur ulang, dan mendukung ekonomi sirkular. Mulailah dari rumah dengan mengecek setiap kemasan plastik yang digunakan, lalu pisahkan sesuai jenisnya agar lebih bermanfaat bagi proses daur ulang.', 'Pemilahan Sampah', 'assets/img/artikel/artikel_1766319383_3956.jpg', '2025-11-06');

-- --------------------------------------------------------

--
-- Table structure for table `data_pengguna`
--

CREATE TABLE `data_pengguna` (
  `user_id` int(11) NOT NULL,
  `total_koin` int(11) DEFAULT 0,
  `sampah_terkumpul_kg` decimal(10,2) DEFAULT 0.00,
  `minyak_terdaur_liter` decimal(10,2) DEFAULT 0.00,
  `status_member` enum('Bronze','Silver','Gold') DEFAULT 'Bronze',
  `tanggal_join` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `data_pengguna`
--

INSERT INTO `data_pengguna` (`user_id`, `total_koin`, `sampah_terkumpul_kg`, `minyak_terdaur_liter`, `status_member`, `tanggal_join`) VALUES
(2, 34181, 1112.00, 80.00, 'Gold', '2025-11-06 22:39:29'),
(6, 2147483647, 99999999.99, 0.00, 'Gold', '2025-11-06 22:39:29'),
(7, 6140, 204.00, 0.00, 'Gold', '2025-11-23 22:46:10'),
(8, 0, 0.00, 0.00, 'Bronze', '2025-11-27 21:18:23'),
(9, 0, 0.00, 0.00, 'Bronze', '2025-11-27 21:19:34'),
(10, 0, 0.00, 0.00, 'Bronze', '2025-11-27 22:33:38'),
(12, 0, 0.00, 0.00, 'Bronze', '2025-11-27 22:49:52'),
(13, 1260, 2.00, 24.00, 'Bronze', '2025-12-20 16:21:34'),
(14, 11240, 23.00, 211.00, 'Gold', '2025-12-20 17:49:37'),
(15, 122150, 1070.00, 1050.00, 'Gold', '2025-12-21 21:05:47'),
(16, 406, 0.00, 5.00, 'Bronze', '2025-12-21 21:28:09'),
(17, 0, 0.00, 0.00, 'Bronze', '2025-12-21 21:36:02'),
(18, 0, 0.00, 0.00, 'Bronze', '2025-12-22 10:00:01'),
(19, 160, 0.00, 2.00, 'Bronze', '2025-12-22 10:05:59');

-- --------------------------------------------------------

--
-- Table structure for table `data_redeem`
--

CREATE TABLE `data_redeem` (
  `id` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `id_hadiah` int(11) NOT NULL,
  `koin_terpakai` int(11) NOT NULL,
  `tanggal_redeem` datetime NOT NULL,
  `status` varchar(50) NOT NULL,
  `catatan_admin` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `data_redeem`
--

INSERT INTO `data_redeem` (`id`, `id_user`, `id_hadiah`, `koin_terpakai`, `tanggal_redeem`, `status`, `catatan_admin`) VALUES
(1, 2, 2, 750, '2025-11-08 04:20:35', 'Pending', NULL),
(2, 2, 4, 300, '2025-11-21 02:34:40', 'Pending', NULL),
(3, 2, 4, 300, '2025-11-21 03:30:41', 'Pending', NULL),
(4, 2, 1, 1000, '2025-11-23 15:55:03', 'Pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `nilai_tukar`
--

CREATE TABLE `nilai_tukar` (
  `id` int(11) NOT NULL,
  `jenis_sampah` varchar(100) NOT NULL,
  `satuan` varchar(10) NOT NULL,
  `koin_per_satuan` int(11) NOT NULL,
  `icon_class` varchar(50) DEFAULT NULL,
  `warna` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nilai_tukar`
--

INSERT INTO `nilai_tukar` (`id`, `jenis_sampah`, `satuan`, `koin_per_satuan`, `icon_class`, `warna`) VALUES
(1, 'Minyak Jelantah', 'Liter', 80, 'bi-droplet-fill', 'text-warning'),
(2, 'Botol Plastik', 'Kg', 25, 'bi-cup-straw', 'text-primary'),
(3, 'Kardus', 'Kg', 35, 'bi-archive-fill', 'text-brown'),
(4, 'Kaleng Aluminium', 'Kg', 55, 'bi-box-seam-fill', 'text-secondary'),
(5, 'Kertas Bekas', 'Kg', 20, 'bi-journal-text', 'text-info');

-- --------------------------------------------------------

--
-- Table structure for table `permintaan_reward`
--

CREATE TABLE `permintaan_reward` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reward_id` int(11) NOT NULL,
  `koin_terpakai` int(11) NOT NULL,
  `tanggal_pengajuan` datetime NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `catatan_admin` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permintaan_reward`
--

INSERT INTO `permintaan_reward` (`id`, `user_id`, `reward_id`, `koin_terpakai`, `tanggal_pengajuan`, `status`, `catatan_admin`) VALUES
(1, 2, 4, 300, '2025-11-23 22:22:20', 'Selesai', 'Reward telah diproses.'),
(2, 2, 1, 1000, '2025-11-23 22:22:59', 'Ditolak', 'Permintaan reward ditolak oleh admin.'),
(3, 7, 4, 300, '2025-11-23 23:08:23', 'Selesai', 'Reward telah diproses.'),
(4, 13, 1, 1000, '2025-12-20 17:19:46', 'Selesai', 'Reward telah diproses.'),
(5, 14, 4, 300, '2025-12-20 17:54:09', 'Selesai', 'Reward telah diproses.'),
(6, 14, 1, 1000, '2025-12-20 18:44:10', 'Selesai', ''),
(7, 14, 1, 1000, '2025-12-20 18:44:59', 'Selesai', ''),
(8, 14, 4, 300, '2025-12-20 18:50:44', 'Selesai', 'Reward telah diproses.'),
(9, 14, 1, 1000, '2025-12-20 19:14:15', 'Selesai', 'Reward telah diproses.'),
(10, 14, 1, 1000, '2025-12-20 20:34:20', 'Selesai', 'Reward telah diproses.'),
(11, 14, 4, 120, '2025-12-21 17:42:16', 'Selesai', 'Reward telah diproses.'),
(12, 14, 1, 200, '2025-12-21 17:42:46', 'Selesai', 'Reward telah diproses.'),
(13, 14, 1, 200, '2025-12-21 18:02:07', 'Selesai', 'Reward telah diproses.'),
(14, 15, 3, 250, '2025-12-21 21:14:36', 'Selesai', 'Reward telah diproses.'),
(15, 14, 4, 120, '2025-12-22 09:04:10', 'Selesai', 'Reward telah diproses.'),
(16, 19, 4, 120, '2025-12-22 10:52:12', 'Selesai', 'Reward telah diproses.');

-- --------------------------------------------------------

--
-- Table structure for table `permintaan_tukar`
--

CREATE TABLE `permintaan_tukar` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `nilai_tukar_id` int(11) NOT NULL,
  `jenis_sampah` varchar(100) NOT NULL,
  `jumlah` int(11) NOT NULL,
  `satuan` varchar(10) NOT NULL,
  `koin_estimasi` int(11) NOT NULL,
  `tanggal_pengajuan` datetime DEFAULT current_timestamp(),
  `status` enum('Pending','Diterima','Ditolak') DEFAULT 'Pending',
  `alasan_admin` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permintaan_tukar`
--

INSERT INTO `permintaan_tukar` (`id`, `user_id`, `nilai_tukar_id`, `jenis_sampah`, `jumlah`, `satuan`, `koin_estimasi`, `tanggal_pengajuan`, `status`, `alasan_admin`) VALUES
(1, 2, 1, 'Minyak Jelantah', 10, 'unit', 500, '2025-11-08 03:34:57', 'Diterima', NULL),
(2, 2, 1, 'Minyak Jelantah', 10, 'unit', 500, '2025-11-08 03:46:38', 'Diterima', NULL),
(4, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-08 04:24:50', 'Diterima', NULL),
(5, 2, 1, 'Minyak Jelantah', 20, 'unit', 1000, '2025-11-08 04:30:54', 'Diterima', NULL),
(6, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-08 04:34:21', 'Diterima', NULL),
(7, 2, 1, 'Minyak Jelantah', 0, 'unit', 1, '2025-11-08 04:36:05', 'Diterima', NULL),
(8, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-08 04:36:36', 'Diterima', NULL),
(9, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-21 02:26:40', 'Diterima', NULL),
(10, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-21 03:29:06', 'Diterima', NULL),
(11, 4, 2, 'Botol Plastik', 1, 'unit', 10, '2025-11-23 09:04:38', 'Pending', NULL),
(12, 4, 2, 'Botol Plastik', 1, 'unit', 10, '2025-11-23 09:05:42', 'Pending', NULL),
(13, 5, 2, 'Botol Plastik', 1, 'unit', 10, '2025-11-23 09:09:07', 'Pending', NULL),
(14, 5, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 09:09:43', 'Pending', NULL),
(15, 2, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 09:12:11', 'Diterima', NULL),
(16, 3, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-23 09:16:19', 'Diterima', NULL),
(17, 3, 1, 'Minyak Jelantah', 2147483647, 'unit', 2147483647, '2025-11-23 09:17:26', 'Diterima', NULL),
(18, 6, 1, 'Minyak Jelantah', 2147483647, 'unit', 2147483647, '2025-11-23 09:19:31', 'Ditolak', 'Ditolak oleh admin.'),
(19, 6, 1, 'Minyak Jelantah', 1000, 'unit', 50000, '2025-11-23 09:21:46', 'Ditolak', 'Ditolak oleh admin.'),
(20, 6, 1, 'Minyak Jelantah', 1000, 'unit', 50000, '2025-11-23 09:26:02', 'Ditolak', 'Ditolak oleh admin.'),
(21, 2, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 09:58:06', 'Ditolak', 'Ditolak oleh admin.'),
(22, 2, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 10:00:15', 'Ditolak', 'Ditolak oleh admin.'),
(23, 2, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 14:09:25', 'Ditolak', 'Ditolak oleh admin.'),
(24, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-23 14:12:47', 'Ditolak', 'Ditolak oleh admin.'),
(25, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-23 14:13:17', 'Ditolak', 'Ditolak oleh admin.'),
(26, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-23 14:29:52', 'Diterima', NULL),
(27, 2, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-23 14:31:46', 'Ditolak', 'Ditolak oleh admin.'),
(28, 2, 4, 'Kaleng Aluminium', 1000, 'unit', 30000, '2025-11-23 16:21:46', 'Diterima', NULL),
(29, 7, 1, 'Minyak Jelantah', 1, 'unit', 50, '2025-11-23 16:46:43', 'Diterima', NULL),
(30, 7, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 17:01:12', 'Diterima', NULL),
(31, 7, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 17:03:14', 'Diterima', NULL),
(32, 7, 4, 'Kaleng Aluminium', 200, 'unit', 6000, '2025-11-23 17:07:59', 'Diterima', NULL),
(33, 7, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 17:17:19', 'Ditolak', 'Ditolak oleh admin.'),
(34, 7, 4, 'Kaleng Aluminium', 1, 'unit', 30, '2025-11-23 17:17:48', 'Diterima', NULL),
(35, 13, 4, 'Kaleng Aluminium', 2, 'unit', 60, '2025-12-20 10:23:57', 'Diterima', 'diterima'),
(36, 13, 1, 'Minyak Jelantah', 10, 'unit', 500, '2025-12-20 10:25:13', 'Diterima', ''),
(37, 13, 1, 'Minyak Jelantah', 10, 'unit', 500, '2025-12-20 10:32:09', 'Diterima', ''),
(38, 13, 1, 'Minyak Jelantah', 1, '0', 50, '2025-12-20 17:18:51', 'Diterima', ''),
(39, 13, 1, 'Minyak Jelantah', 1, '0', 50, '2025-12-20 17:38:13', 'Diterima', ''),
(40, 13, 1, 'Minyak Jelantah', 2, '0', 100, '2025-12-20 17:39:25', 'Diterima', ''),
(41, 14, 4, 'Kaleng Aluminium', 1, '0', 30, '2025-12-20 17:52:06', 'Diterima', ''),
(42, 14, 1, 'Minyak Jelantah', 1, '0', 50, '2025-12-20 17:53:04', 'Diterima', ''),
(43, 14, 1, 'Minyak Jelantah', 10, '0', 500, '2025-12-20 17:53:35', 'Diterima', ''),
(44, 14, 1, 'Minyak Jelantah', 100, '0', 5000, '2025-12-20 18:42:55', 'Diterima', ''),
(45, 14, 1, 'Minyak Jelantah', 100, '0', 5000, '2025-12-20 19:13:21', 'Diterima', ''),
(46, 14, 4, 'Kaleng Aluminium', 2, '0', 60, '2025-12-20 20:34:43', 'Diterima', ''),
(47, 14, 4, 'Kaleng Aluminium', 20, '0', 600, '2025-12-20 20:35:12', 'Diterima', ''),
(48, 15, 5, 'Kertas Bekas', 20, '0', 400, '2025-12-21 21:08:44', 'Diterima', ''),
(49, 15, 1, 'Minyak Jelantah', 50, '0', 4000, '2025-12-21 21:10:13', 'Diterima', ''),
(50, 15, 4, 'Kaleng Aluminium', 50, '0', 2750, '2025-12-21 21:10:20', 'Diterima', ''),
(51, 15, 1, 'Minyak Jelantah', 500, '0', 40000, '2025-12-21 21:31:23', 'Ditolak', ''),
(52, 15, 1, 'Minyak Jelantah', 1000, '0', 80000, '2025-12-21 21:32:50', 'Diterima', 'ga'),
(53, 15, 3, 'Kardus', 1000, '0', 35000, '2025-12-21 21:39:03', 'Diterima', ''),
(54, 16, 1, 'Minyak Jelantah', 5, '0', 406, '2025-12-22 09:05:39', 'Diterima', ''),
(55, 19, 1, 'Minyak Jelantah', 1, '0', 80, '2025-12-22 10:50:58', 'Diterima', ''),
(56, 19, 1, 'Minyak Jelantah', 1, '0', 80, '2025-12-22 10:51:59', 'Diterima', ''),
(57, 19, 1, 'Minyak Jelantah', 1, '0', 80, '2025-12-22 10:52:55', 'Ditolak', '');

-- --------------------------------------------------------

--
-- Table structure for table `pesan_kontak`
--

CREATE TABLE `pesan_kontak` (
  `id` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telepon` varchar(30) DEFAULT NULL,
  `subjek` varchar(150) NOT NULL,
  `pesan` text NOT NULL,
  `status_baca` enum('Belum Dibaca','Dibaca') DEFAULT 'Belum Dibaca',
  `tanggal_kirim` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pesan_kontak`
--

INSERT INTO `pesan_kontak` (`id`, `nama`, `email`, `telepon`, `subjek`, `pesan`, `status_baca`, `tanggal_kirim`) VALUES
(1, 'IRFAN', 'palsu3467@gmail.com', '081216873459', 'BERTANYA', 'apa disini harus mengantar sampah offline', 'Dibaca', '2025-12-20 11:22:36'),
(2, 'Lepi', 'nama@gmail.com', '82169534', 'cara tukar gmn ya', 'Bantu aku kasih sraan', 'Dibaca', '2025-12-21 14:12:42');

-- --------------------------------------------------------

--
-- Table structure for table `reward_list`
--

CREATE TABLE `reward_list` (
  `id` int(11) NOT NULL,
  `nama_reward` varchar(100) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `koin_harga` int(11) NOT NULL,
  `stok` int(11) DEFAULT 0,
  `gambar` varchar(255) DEFAULT NULL,
  `kategori` varchar(50) DEFAULT 'Umum'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reward_list`
--

INSERT INTO `reward_list` (`id`, `nama_reward`, `deskripsi`, `koin_harga`, `stok`, `gambar`, `kategori`) VALUES
(1, 'E-Wallet Rp.20.000', 'Saldo E-Wallet senilai Rp 20.000', 200, 50, 'assets/img/reward_1_1766323230.jpg', 'Voucher'),
(2, 'Tumbler Eco-Friendly', 'Tumbler premium', 420, 100, 'assets/img/reward_2_1766323467.jpg', 'Produk'),
(3, 'Totebag Eco-Friendly', 'Tas belanja kain eksklusif', 250, 49, 'assets/img/reward_3_1766323481.jpg', 'Produk'),
(4, 'Bibit Tanaman Hias', 'Satu set bibit tanaman hias', 120, 197, 'assets/img/reward_4_1766322495.jpg', 'Tanaman'),
(5, 'E-Wallet Rp.50.000', 'Saldo E-Wallet senilai Rp 50.000', 500, 50, 'assets/img/reward_5_1766323510.jpg', 'Voucher'),
(6, 'coba', 'coba', 200, 10, NULL, 'Voucher');

-- --------------------------------------------------------

--
-- Table structure for table `transaksi`
--

CREATE TABLE `transaksi` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `jenis_transaksi` enum('Tukar Sampah','Redeem','Bonus') NOT NULL,
  `deskripsi` varchar(255) NOT NULL,
  `jumlah_koin` int(11) NOT NULL,
  `tanggal_transaksi` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaksi`
--

INSERT INTO `transaksi` (`id`, `user_id`, `jenis_transaksi`, `deskripsi`, `jumlah_koin`, `tanggal_transaksi`) VALUES
(1, 2, 'Tukar Sampah', '', 1000, '2025-11-06 22:33:39'),
(2, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10.00unit)', 500, '2025-11-08 09:47:51'),
(4, 2, 'Redeem', 'Redeem: Tumbler Eco-Friendly', -750, '2025-11-08 04:20:35'),
(5, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10.00unit)', 500, '2025-11-08 10:21:11'),
(6, 2, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (20.00unit)', 600, '2025-11-08 10:21:14'),
(7, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1.00unit)', 50, '2025-11-08 10:25:03'),
(8, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (20unit)', 1000, '2025-11-08 10:31:05'),
(9, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1unit)', 50, '2025-11-08 10:34:29'),
(10, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (0unit)', 1, '2025-11-08 10:36:13'),
(11, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1unit)', 50, '2025-11-08 10:36:46'),
(12, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1unit)', 50, '2025-11-21 08:27:14'),
(13, 2, 'Redeem', 'Redeem: Bibit Tanaman Hias', -300, '2025-11-21 02:34:40'),
(14, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1unit)', 50, '2025-11-21 09:29:36'),
(15, 2, 'Redeem', 'Redeem: Bibit Tanaman Hias', -300, '2025-11-21 03:30:41'),
(16, 3, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (2147483647unit)', 2147483647, '2025-11-23 15:17:34'),
(17, 2, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (1unit)', 30, '2025-11-23 15:21:56'),
(18, 3, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1unit)', 50, '2025-11-23 15:21:58'),
(19, 2, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1 unit)', 50, '2025-11-23 20:30:10'),
(20, 2, 'Redeem', 'Redeem: Voucher Belanja', -1000, '2025-11-23 15:55:03'),
(21, 2, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (1000 unit)', 30000, '2025-11-23 22:21:55'),
(22, 2, '', 'Redeem: Bibit Tanaman Hias', 300, '2025-11-23 22:22:20'),
(23, 2, '', 'Redeem: Voucher Belanja', 1000, '2025-11-23 22:22:59'),
(24, 2, '', 'REFUND: Redeem Reward \'Voucher Belanja\' ditolak admin.', 1000, '2025-11-23 22:36:55'),
(25, 7, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1 unit)', 50, '2025-11-23 22:47:08'),
(26, 7, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (1 unit)', 30, '2025-11-23 23:01:20'),
(27, 7, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (1 unit)', 30, '2025-11-23 23:03:23'),
(28, 7, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (200 unit)', 6000, '2025-11-23 23:08:06'),
(29, 7, '', 'Redeem: Bibit Tanaman Hias', 300, '2025-11-23 23:08:23'),
(30, 7, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (1 unit)', 30, '2025-11-23 23:41:10'),
(31, 13, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10)', 50, '2025-12-20 17:18:57'),
(32, 13, 'Redeem', 'Redeem: Voucher Belanja', -1000, '2025-12-20 17:36:55'),
(33, 13, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10)', 50, '2025-12-20 17:38:27'),
(34, 13, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (20)', 100, '2025-12-20 17:39:47'),
(35, 14, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (10)', 30, '2025-12-20 17:52:27'),
(36, 14, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10)', 50, '2025-12-20 17:53:10'),
(37, 14, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (100)', 500, '2025-12-20 17:53:42'),
(38, 14, 'Redeem', 'Redeem: Bibit Tanaman Hias', -300, '2025-12-20 17:54:13'),
(39, 14, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1000)', 5000, '2025-12-20 18:43:03'),
(40, 14, 'Redeem', 'Redeem: Voucher Belanja', -1000, '2025-12-20 18:44:16'),
(41, 14, 'Redeem', 'Redeem: Voucher Belanja', -1000, '2025-12-20 18:45:05'),
(42, 14, 'Redeem', 'Redeem: Bibit Tanaman Hias', -300, '2025-12-20 18:50:53'),
(43, 14, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (1000)', 5000, '2025-12-20 19:13:27'),
(44, 14, 'Redeem', 'Redeem: Voucher Belanja', -1000, '2025-12-20 19:14:22'),
(45, 14, 'Redeem', 'Redeem: Voucher Belanja', -1000, '2025-12-20 20:34:24'),
(46, 14, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (20)', 60, '2025-12-20 20:34:51'),
(47, 14, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (200)', 600, '2025-12-20 20:35:15'),
(48, 14, 'Redeem', 'Redeem: Bibit Tanaman Hias', -120, '2025-12-21 17:42:25'),
(49, 14, 'Redeem', 'Redeem: Saldo Dana Rp.20.000', -200, '2025-12-21 17:42:56'),
(50, 14, 'Redeem', 'Redeem: Saldo Dana Rp.20.000', -200, '2025-12-21 18:02:19'),
(51, 15, 'Tukar Sampah', 'Tukar Sampah: Kertas Bekas (200)', 400, '2025-12-21 21:09:23'),
(52, 15, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (500)', 4000, '2025-12-21 21:10:19'),
(53, 15, 'Tukar Sampah', 'Tukar Sampah: Kaleng Aluminium (500)', 2750, '2025-12-21 21:10:30'),
(54, 15, 'Redeem', 'Redeem: Totebag Eco-Friendly', -250, '2025-12-21 21:14:43'),
(55, 15, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10000)', 80000, '2025-12-21 21:33:01'),
(56, 15, 'Tukar Sampah', 'Tukar Sampah: Kardus (10000)', 35000, '2025-12-21 21:39:15'),
(57, 14, 'Redeem', 'Redeem: Bibit Tanaman Hias', -120, '2025-12-22 09:04:18'),
(58, 16, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (50)', 406, '2025-12-22 10:50:24'),
(59, 19, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10)', 80, '2025-12-22 10:51:05'),
(60, 19, 'Tukar Sampah', 'Tukar Sampah: Minyak Jelantah (10)', 80, '2025-12-22 10:52:05'),
(61, 19, 'Redeem', 'Redeem: Bibit Tanaman Hias', -120, '2025-12-22 10:52:18');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `koin` int(11) NOT NULL DEFAULT 0,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_token` varchar(64) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expired` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `koin`, `email_verified`, `verify_token`, `reset_token`, `reset_expired`, `created_at`) VALUES
(2, 'gonin', 'gonindamaiselamanya09@gmail.com', '123', 29931, 0, NULL, NULL, NULL, '2025-10-29 14:07:17'),
(3, 'doyok', 'akuselamany@gmail.com', '321', 2147483647, 0, NULL, NULL, NULL, '2025-10-29 14:26:15'),
(6, 'username', 'user@mail.com', '123', 10000, 0, NULL, NULL, NULL, '2025-11-23 08:19:11'),
(7, 'andin', 'irfan@example.com', '123', 5840, 0, NULL, NULL, NULL, '2025-11-23 15:46:10'),
(13, 'hans', 'palsu3467@gmail.com', '123', 260, 1, NULL, NULL, NULL, '2025-12-20 09:21:34'),
(14, 'raven', 'fann.rafi.ananda@gmail.com', '123', 8000, 1, NULL, NULL, NULL, '2025-12-20 10:49:37'),
(15, 'lepi', 'bomatsubkptua@gmail.com', '123', 122150, 1, NULL, NULL, NULL, '2025-12-21 14:36:02'),
(16, 'dinda', 'dindadh2@gmail.com', '12345', 406, 1, NULL, NULL, NULL, '2025-12-21 14:28:09'),
(18, 'jack', 'irfanrafi0647@gmail.com', '$2y$10$mWxfgiQTMJN0pS/J.oDl7u7tTVj6ash3QST8uHUGE9HL.3Ik0kReu', 0, 1, NULL, NULL, NULL, '2025-12-22 03:00:01'),
(19, 'Iza', 'faiz.gibran.rezi@gmail.com', '$2y$10$TrWia.Q9387VFze1/LoI7OCeI0Iy1hihRXf5NuEEE4Rcr9DmjNODS', 40, 1, NULL, NULL, NULL, '2025-12-22 03:05:59');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id_admin`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `artikel`
--
ALTER TABLE `artikel`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `data_pengguna`
--
ALTER TABLE `data_pengguna`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `data_redeem`
--
ALTER TABLE `data_redeem`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `nilai_tukar`
--
ALTER TABLE `nilai_tukar`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permintaan_reward`
--
ALTER TABLE `permintaan_reward`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `permintaan_tukar`
--
ALTER TABLE `permintaan_tukar`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pesan_kontak`
--
ALTER TABLE `pesan_kontak`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reward_list`
--
ALTER TABLE `reward_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transaksi`
--
ALTER TABLE `transaksi`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id_admin` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `artikel`
--
ALTER TABLE `artikel`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `data_redeem`
--
ALTER TABLE `data_redeem`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `nilai_tukar`
--
ALTER TABLE `nilai_tukar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `permintaan_reward`
--
ALTER TABLE `permintaan_reward`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `permintaan_tukar`
--
ALTER TABLE `permintaan_tukar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `pesan_kontak`
--
ALTER TABLE `pesan_kontak`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `reward_list`
--
ALTER TABLE `reward_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `transaksi`
--
ALTER TABLE `transaksi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `permintaan_reward`
--
ALTER TABLE `permintaan_reward`
  ADD CONSTRAINT `permintaan_reward_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `data_pengguna` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
