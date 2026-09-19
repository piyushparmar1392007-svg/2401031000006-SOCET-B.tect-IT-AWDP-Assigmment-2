# ShopKart - PHP + MySQL E-Commerce Website

## Technology
- HTML
- CSS
- PHP
- MySQL
- XAMPP
- No React, Node.js, Laravel, Python or other frameworks.

## Default Admin
Email: admin@example.com
Password: admin123

The first time `index.php` runs, it creates the admin account automatically if it does not exist.

## XAMPP Setup

1. Install XAMPP.
2. Start Apache and MySQL.
3. Open `http://localhost/phpmyadmin/`.
4. Click **Import**.
5. Select `database.sql`.
6. Run/import the SQL.
7. Copy the complete `ecommerce_shop` folder into:
   `C:\xampp\htdocs\`
8. Open:
   `http://localhost/ecommerce_shop/`
9. Login using the default admin account above.
10. From Admin Dashboard you can add categories, products, coupons, manage users and update orders.

## Image Uploads
The PHP application automatically creates an `uploads` folder when an image is uploaded.
If XAMPP/Windows blocks file creation, create an `uploads` folder manually inside the project and give it write permission.

## Main Features
- Home page
- Product search/filter/sort
- Categories
- Product details
- Product image upload + multiple gallery images
- User registration/login/logout
- Session authentication
- Password hashing and change password
- Profile editing and profile image
- Cart, quantity update and remove
- Coupon validation, minimum order, maximum discount and expiry
- Checkout
- COD and safe demo online payment simulation
- Orders and reorder
- Payment records
- Contact messages
- Admin dashboard
- User block/unblock/delete
- Category CRUD
- Product CRUD
- Order status + delivered/not delivered
- Coupon CRUD
- Responsive UI

## Security Notes
The project uses:
- `password_hash()` and `password_verify()`
- PHP sessions
- CSRF tokens
- Prepared statements for important user-input queries
- Output escaping with `htmlspecialchars()`
- Admin authorization checks
- Blocked-user login prevention

## Important
This is an academic/demo e-commerce system. The online payment option is deliberately simulated and does not connect to a real payment gateway.
