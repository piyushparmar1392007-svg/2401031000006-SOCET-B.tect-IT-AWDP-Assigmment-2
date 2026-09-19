CREATE DATABASE IF NOT EXISTS ecommerce_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ecommerce_db;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  mobile VARCHAR(20) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  state VARCHAR(100) DEFAULT NULL,
  pincode VARCHAR(20) DEFAULT NULL,
  profile_image VARCHAR(255) DEFAULT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  status ENUM('active','blocked') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role(role),
  INDEX idx_users_status(status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL,
  description TEXT,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_categories_status(status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  product_name VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount DECIMAL(5,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  sku VARCHAR(80) DEFAULT NULL UNIQUE,
  image VARCHAR(255) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_products_category(category_id),
  INDEX idx_products_status(status),
  CONSTRAINT fk_products_category FOREIGN KEY(category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  image VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product_images_product(product_id),
  CONSTRAINT fk_product_images_product FOREIGN KEY(product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coupons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  coupon_code VARCHAR(50) NOT NULL UNIQUE,
  discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  minimum_order DECIMAL(10,2) NOT NULL DEFAULT 0,
  maximum_discount DECIMAL(10,2) NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  expiry_date DATE NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_coupons_code(coupon_code),
  INDEX idx_coupons_dates(start_date,expiry_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cart (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_user_product(user_id,product_id),
  INDEX idx_cart_user(user_id),
  CONSTRAINT fk_cart_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  coupon_code VARCHAR(50) DEFAULT NULL,
  discount DECIMAL(10,2) NOT NULL DEFAULT 0,
  shipping_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
  final_amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(50) NOT NULL,
  payment_status ENUM('Pending','Paid','Failed') NOT NULL DEFAULT 'Pending',
  order_status ENUM('Pending','Confirmed','Processing','Shipped','Out for Delivery','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
  delivered_status ENUM('Delivered','Not Delivered') NOT NULL DEFAULT 'Not Delivered',
  shipping_name VARCHAR(100) NOT NULL,
  shipping_email VARCHAR(150) NOT NULL,
  shipping_mobile VARCHAR(20) NOT NULL,
  shipping_address VARCHAR(255) NOT NULL,
  shipping_city VARCHAR(100) NOT NULL,
  shipping_state VARCHAR(100) NOT NULL,
  shipping_pincode VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_orders_user(user_id),
  INDEX idx_orders_status(order_status),
  INDEX idx_orders_date(created_at),
  CONSTRAINT fk_orders_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(180) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  quantity INT NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  INDEX idx_order_items_order(order_id),
  INDEX idx_order_items_product(product_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NOT NULL,
  payment_method VARCHAR(50) NOT NULL,
  transaction_id VARCHAR(100) NOT NULL UNIQUE,
  amount DECIMAL(10,2) NOT NULL,
  payment_status ENUM('Pending','Paid','Failed') NOT NULL DEFAULT 'Pending',
  payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payments_order(order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  mobile VARCHAR(20) DEFAULT NULL,
  subject VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_email(email)
) ENGINE=InnoDB;

INSERT IGNORE INTO coupons(coupon_code,discount_type,discount_value,minimum_order,maximum_discount,start_date,expiry_date,status)
VALUES('WELCOME10','percent',10,500,200,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 90 DAY),'active');

INSERT IGNORE INTO products(category_id,product_name,description,price,discount,stock,sku,status)
SELECT c.id,'Wireless Headphones','Comfortable wireless headphones with clear sound and long battery life.',2499,15,25,'ELEC-001','active'
FROM categories c WHERE c.category_name='Electronics';

INSERT IGNORE INTO products(category_id,product_name,description,price,discount,stock,sku,status)
SELECT c.id,'Smart Watch','Modern smartwatch with activity tracking, notifications and stylish design.',3999,20,18,'ELEC-002','active'
FROM categories c WHERE c.category_name='Electronics';

INSERT IGNORE INTO products(category_id,product_name,description,price,discount,stock,sku,status)
SELECT c.id,'Classic Casual Shirt','Comfortable everyday casual shirt suitable for college and office wear.',1299,10,30,'FASH-001','active'
FROM categories c WHERE c.category_name='Fashion';

INSERT IGNORE INTO products(category_id,product_name,description,price,discount,stock,sku,status)
SELECT c.id,'Everyday Backpack','Durable backpack with multiple compartments for college, travel and daily use.',1799,12,20,'ACC-001','active'
FROM categories c WHERE c.category_name='Accessories';

INSERT IGNORE INTO products(category_id,product_name,description,price,discount,stock,sku,status)
SELECT c.id,'LED Desk Lamp','Minimal desk lamp with adjustable brightness for study and work.',999,5,35,'HOME-001','active'
FROM categories c WHERE c.category_name='Home & Living';
