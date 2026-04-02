CREATE TABLE `consumption` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `product_id` int(11) NOT NULL,
    `qty` int(11) NOT NULL DEFAULT 1,
    `amount` decimal(10,2) DEFAULT 0.00,
    `paid_amount` decimal(10,2) DEFAULT 0.00,
    `done_by` varchar(100) DEFAULT NULL,
    `consumption_date` date NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `product_id` (`product_id`),
    CONSTRAINT `consumption_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `expenses` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `description` varchar(255) NOT NULL,
    `category` varchar(100) DEFAULT NULL,
    `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
    `expense_date` date NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `loans` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `product_id` int(11) NOT NULL,
    `qty` int(11) NOT NULL DEFAULT 1,
    `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
    `client` varchar(100) NOT NULL,
    `phone` varchar(20) DEFAULT NULL,
    `loan_date` date NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `product_id` (`product_id`),
    CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `loan_payments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `loan_id` int(11) NOT NULL,
    `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
    `payment_date` date NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `loan_id` (`loan_id`),
    CONSTRAINT `loan_payments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS boaster (
    id INT AUTO_INCREMENT PRIMARY KEY,
    giver VARCHAR(255) NOT NULL,
    amount DECIMAL(12,0) NOT NULL,
    date DATE NOT NULL,
    description TEXT,
    phone VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
