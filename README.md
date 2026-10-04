# Tixtak

Tixtak là ứng dụng Laravel 12 đặt vé sự kiện. Tài liệu này ghi luồng chức năng, vị trí các hàm chính và kết quả rà soát mã có dấu hiệu chưa được dùng.

## Công nghệ

- PHP 8.2+, Laravel 11, Blade, Tailwind CSS 4 và Vite.
- SQLite mặc định; có thể cấu hình MySQL trong `.env`.
- OTP đăng ký và đặt lại mật khẩu được gửi qua email.
- Thanh toán VNPay Sandbox; QR vé được tạo sau khi đơn được xác nhận.

## Cài đặt

Yêu cầu PHP 8.2+, Composer, Node.js và npm.

```bash
composer install
```

Tạo `.env` từ mẫu và tạo khóa ứng dụng:

```powershell
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Force database/database.sqlite
```

Điền thông tin cơ sở dữ liệu, email và VNPay trong `.env`. Để nhận callback VNPay, đặt `APP_URL` thành URL HTTPS có thể truy cập từ VNPay và đăng ký IPN URL `https://<host>/payments/vnpay/ipn` với merchant.

```bash
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Trong lúc phát triển giao diện, chạy `npm run dev`. `composer run dev` cũng khởi động scheduler phục vụ việc hết hạn đơn giữ vé. Trên máy chủ, chạy `php artisan schedule:run` mỗi phút.

## Luồng chức năng và hàm chính

### Đăng nhập, đăng ký, khôi phục mật khẩu

Các route nằm trong `routes/web.php`; xử lý chính ở `app/Http/Controllers/AuthController.php`.

| Chức năng | Hàm | Tệp |
| --- | --- | --- |
| Hiển thị form đăng nhập | `login` | `app/Http/Controllers/AuthController.php` |
| Kiểm tra thông tin, tạo phiên đăng nhập và chuyển hướng theo quyền | `authenticate` | `app/Http/Controllers/AuthController.php` |
| Hiển thị form đăng ký và xử lý yêu cầu sửa email đang chờ | `register` | `app/Http/Controllers/AuthController.php` |
| Gửi OTP đăng ký | `sendRegistrationCode` | `app/Http/Controllers/AuthController.php` |
| Xác minh OTP và tạo tài khoản | `verifyRegistrationCode` | `app/Http/Controllers/AuthController.php` |
| Hiển thị form quên mật khẩu và xử lý yêu cầu sửa email | `forgotPassword` | `app/Http/Controllers/AuthController.php` |
| Gửi OTP đặt lại mật khẩu | `sendPasswordResetCode` | `app/Http/Controllers/AuthController.php` |
| Xác minh OTP và lưu mật khẩu mới | `resetPassword` | `app/Http/Controllers/AuthController.php` |
| Kết thúc phiên và đăng xuất | `logout` | `app/Http/Controllers/AuthController.php` |
| Tạo, lưu cache và gửi OTP qua email (hàm nội bộ) | `sendOtp` | `app/Http/Controllers/AuthController.php` |
| Tạo khóa cache OTP theo mục đích và email (hàm nội bộ) | `otpCacheKey` | `app/Http/Controllers/AuthController.php` |
| Kiểm tra tài khoản quản trị | `isAdmin` | `app/Models/User.php` |
| Giới hạn quyền truy cập route quản trị | `handle` | `app/Http/Middleware/EnsureUserIsAdmin.php` |

Giao diện tương ứng: `resources/views/auth/login.blade.php`, `register.blade.php` và `forgot-password.blade.php`.

### Sự kiện và chọn vé

| Chức năng | Hàm/điểm xử lý | Tệp |
| --- | --- | --- |
| Chuyển người dùng từ trang gốc đến đăng nhập hoặc trang phù hợp | Closure route `/` | `routes/web.php` |
| Hiển thị danh sách sự kiện cho khách đã đăng nhập | Closure route `/dashboard` | `routes/web.php` |
| Hiển thị chọn vé, hình giới thiệu và sơ đồ chỗ ngồi | Closure route `/select-ticket/{event:slug}` | `routes/web.php` |
| Hiển thị loại vé, số lượng đang được giữ và sơ đồ | Closure route `/ticket-detail/{event:slug}` | `routes/web.php` |
| Quan hệ loại vé của sự kiện | `ticketTypes` | `app/Models/Event.php` |
| Hiển thị và quản lý sự kiện trong trang quản trị | `index`, `events`, `create`, `store`, `edit`, `update`, `destroy`, `validatedEvent` | `app/Http/Controllers/AdminController.php` |

Giao diện khách nằm tại `resources/views/home.blade.php`, `select-ticket.blade.php` và `ticket-detail.blade.php`. Form quản trị sự kiện nằm tại `resources/views/admin/events/`.

### Đặt đơn, giữ vé và quản lý vé

| Chức năng | Hàm | Tệp |
| --- | --- | --- |
| Tạo đơn, kiểm tra số lượng, tính tiền và chống gửi trùng | `store` | `app/Http/Controllers/OrderController.php` |
| Tính tổng số vé của các đơn đang giữ | `reservedQuantity`, `reservedQuantities` | `app/Services/OrderReservationService.php` |
| Hủy các đơn chờ đã hết hạn | `expirePendingOrders` | `app/Services/OrderReservationService.php` |
| Hiển thị chi tiết/thanh toán đơn | `payment` | `app/Http/Controllers/OrderController.php` |
| Hiển thị vé của người dùng và phục hồi thanh toán đã ghi nhận | `myTickets` | `app/Http/Controllers/OrderController.php` |
| Hiển thị lịch sử giao dịch | `transactions` | `app/Http/Controllers/OrderController.php` |
| Hủy đơn của người dùng | `cancel` | `app/Http/Controllers/OrderController.php` |
| Phục hồi các giao dịch thành công đã lưu nhưng đơn chưa xác nhận | `recoverRecordedSuccesses` | `app/Services/PaymentRecoveryService.php` |
| Tạo QR cho từng vé trong đơn | `issueForOrder` | `app/Services/TicketQrService.php` |
| Quan hệ người dùng, vé, đơn, giao dịch và QR | `orders`, `user`, `items`, `paymentTransactions`, `order`, `ticketType`, `qrInfo`, `event` | `app/Models/` |

Các trang khách tương ứng: `resources/views/payment.blade.php`, `my-tickets.blade.php` và `transactions/index.blade.php`.

### Thanh toán VNPay

| Chức năng | Hàm | Tệp |
| --- | --- | --- |
| Bắt đầu chuyển người dùng sang VNPay | `start` | `app/Http/Controllers/VnpayController.php` |
| Nhận URL trả về, kiểm tra và cập nhật kết quả thanh toán | `returned` | `app/Http/Controllers/VnpayController.php` |
| Nhận IPN và phản hồi VNPay | `ipn` | `app/Http/Controllers/VnpayController.php` |
| Xác nhận thanh toán, cập nhật tồn kho và phát hành QR | `confirmPayment` | `app/Http/Controllers/VnpayController.php` |
| Lưu dữ liệu callback để đối soát | `recordCallback` | `app/Http/Controllers/VnpayController.php` |
| Tạo phản hồi JSON theo giao thức IPN | `ipnResponse` | `app/Http/Controllers/VnpayController.php` |
| Kiểm tra cấu hình, tạo URL, xác minh chữ ký và đối chiếu đơn | `isConfigured`, `createPaymentUrl`, `verifySignature`, `matchesOrder` | `app/Services/VnpayService.php` |
| Bảo đảm cấu hình VNPay đã đầy đủ (hàm nội bộ) | `ensureConfigured` | `app/Services/VnpayService.php` |

### Quản trị, giao dịch và quét QR

| Chức năng | Hàm | Tệp |
| --- | --- | --- |
| Dashboard và báo cáo quản trị | `index` | `app/Http/Controllers/AdminController.php` |
| Danh sách, lọc đơn hàng | `orders` | `app/Http/Controllers/AdminController.php` |
| Tạo đơn từ trang quản trị | `createOrder`, `storeOrder` | `app/Http/Controllers/AdminController.php` |
| Cập nhật trạng thái đơn, tồn kho và QR | `updateOrder` | `app/Http/Controllers/AdminController.php` |
| Tìm kiếm khách hàng | `customers` | `app/Http/Controllers/AdminController.php` |
| Hiển thị trang quét QR | `index` | `app/Http/Controllers/QrScanController.php` |
| Tìm vé bằng mã QR | `lookup` | `app/Http/Controllers/QrScanController.php` |
| Đánh dấu vé đã check-in | `checkIn` | `app/Http/Controllers/QrScanController.php` |

Giao diện quản trị ở `resources/views/admin/`; giao diện quét QR ở `resources/views/admin/qr-scan.blade.php`.

## Rà soát mã có dấu hiệu chưa dùng

Đã rà soát các tệp được Git quản lý và các mã nguồn ứng dụng/routes/views/configuration/migrations/seeders/tests; loại khỏi phạm vi các mục bị `.gitignore` bỏ qua như `.env`, `vendor`, `node_modules`, `public/build` và cache. Không thấy hàm nghiệp vụ nào trong `app/` có thể kết luận chắc chắn là không dùng: các action được ánh xạ bằng route, các helper được gọi trong controller/service, còn quan hệ model được truy cập qua Eloquent.

Các mục cần xác minh thủ công:

- `vnpay_php/` chứa bộ mã và tài nguyên VNPay độc lập. Không tìm thấy tham chiếu từ route Laravel hoặc ứng dụng trong `app/`; có thể là mã mẫu không dùng trong luồng hiện tại, nhưng chưa thể kết luận nó không được chạy độc lập.
- `tests/Feature/ExampleTest.php` và `tests/Unit/ExampleTest.php` có tên dạng test mẫu. Đây là mã kiểm thử, không phải mã chạy ứng dụng; cần mở nội dung trước khi đánh giá có thể bỏ.
- Các migration cũ và migration sửa dữ liệu vẫn là lịch sử thay đổi schema/dữ liệu. Không nên coi chúng là mã thừa chỉ vì migration mới hơn đã thay đổi hoặc loại bỏ một cột.

Không có tệp mã nào được xóa hoặc sửa trong quá trình rà soát; README là tệp duy nhất được cập nhật.

## Email và tài khoản mẫu

Thiết lập `MAIL_*` để gửi OTP thật. Mailer `log` trong cấu hình mẫu ghi thư vào log thay vì gửi. Seeder tạo tài khoản demo; xem `database/seeders/DemoUsersSeeder.php` và đổi mật khẩu trước khi triển khai.

## Lệnh hữu ích

```bash
php artisan route:list
php artisan migrate --seed
npm run dev
npm run build
```
