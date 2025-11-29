# Flash-Sale Checkout API

A high-concurrency Laravel API for flash-sale checkout system that handles stock management, order processing, and payment webhooks with proper concurrency control to prevent overselling.

## 🚀 Features

- **Stock Hold System**: Temporarily reserve stock for customers during checkout
- **Concurrency Control**: Prevents overselling using database locks and transactions
- **Webhook Idempotency**: Handles duplicate payment webhooks gracefully
- **Cache Layer**: Product caching with automatic invalidation
- **Hold Expiration**: Automatic stock restoration for expired holds

## 📋 Requirements

- PHP 8.2+
- Laravel 12
- MySQL/PostgreSQL (for proper locking support)
- Composer

## 🛠️ Installation

```bash
# Clone the repository
git clone <repository-url>
cd payin

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure your database in .env file
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=payin
# DB_USERNAME=root
# DB_PASSWORD=

# Run migrations
php artisan migrate

# (Optional) Seed sample data
php artisan db:seed
```

## 🔧 Configuration

### Scheduler
Add to your crontab for hold expiration:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

Or run the expire command manually:
```bash
php artisan app:expire-holds
```

## 📡 API Endpoints

### 1. Get Product Details
```http
GET /api/products/{id}
```

**Response (200 OK):**
```json
{
    "data": {
        "id": 1,
        "name": "Flash Sale Item",
        "total_stock": 100,
        "price": "99.99",
        "created_at": "2025-11-29T10:00:00.000000Z",
        "updated_at": "2025-11-29T10:00:00.000000Z"
    }
}
```

### 2. Create Stock Hold
```http
POST /api/holds
Content-Type: application/json

{
    "product_id": 1,
    "qty": 2
}
```

**Response (201 Created):**
```json
{
    "data": {
        "hold_id": 1,
        "expires_at": "2025-11-29T10:02:00.000000Z"
    }
}
```

**Error Response (422 Unprocessable Entity):**
```json
{
    "message": "Insufficient stock available.",
    "errors": {
        "product_id": ["Insufficient stock available."]
    }
}
```

### 3. Create Order
```http
POST /api/orders
Content-Type: application/json

{
    "hold_id": 1
}
```

**Response (201 Created):**
```json
{
    "data": {
        "id": 1,
        "hold_id": 1,
        "status": "pending",
        "total_amount": "199.98",
        "created_at": "2025-11-29T10:01:00.000000Z",
        "updated_at": "2025-11-29T10:01:00.000000Z"
    }
}
```

### 4. Payment Webhook
```http
POST /api/payments/webhook
Content-Type: application/json

{
    "idempotency_key": "unique-payment-key-123",
    "data": {
        "hold_id": 1,
        "status": "paid"
    }
}
```

**Response (200 OK):**
```json
{
    "data": {
        "hold_id": 1,
        "status": "paid"
    }
}
```

## 🏗️ Architecture & Design Decisions

### 1. Concurrency Control Strategy

**Problem**: Multiple users trying to buy limited stock simultaneously can cause overselling.

**Solution**: 
- `lockForUpdate()` on product during hold validation
- `lockForUpdate()` on hold during order creation
- `WrapRequestInTransaction` middleware for atomic operations

```php
// HoldRequest.php
$product = Product::where('id', $value)->lockForUpdate()->first();
```

### 2. Race Condition: Webhook Before Order

**Problem**: Payment webhook might arrive before the order is created in the database.

**Solution**: 
- Store webhook in `pending_webhooks` table if order doesn't exist
- `CheckOrderStatus` job processes pending webhooks after order creation

```
Timeline:
[Webhook arrives] -> [Store in pending_webhooks]
[Order created] -> [Job checks pending_webhooks] -> [Update order status]
```

### 3. Idempotency

**Problem**: Payment provider might send the same webhook multiple times.

**Solution**: 
- `webhook_logs` table with `idempotency_key` as primary key
- Return cached response for duplicate requests

### 4. Cache Invalidation

**Problem**: Product stock changes but cache returns stale data.

**Solution**: 
- Manual cache invalidation after every stock change
- `Cache::forget($product_id)` in HoldController, WebhookController, ExpireHolds command

### 5. Hold Expiration

**Problem**: User holds stock but never completes purchase.

**Solution**: 
- `expires_at` timestamp on holds (2 minutes TTL)
- `app:expire-holds` command restores stock for expired holds

## 🧪 Testing

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --filter=StressTest
php artisan test --filter=ConcurrencyTest
php artisan test --filter=CacheTest

# Run with coverage
php artisan test --coverage
```

### Test Suites

| Suite | Tests | Description |
|-------|-------|-------------|
| StressTest | 9 | Overselling prevention, high volume |
| ConcurrencyTest | 6 | Race conditions, locking |
| CacheTest | 7 | Cache invalidation, TTL |
| WebhookTest | 8 | Idempotency, status updates |
| HoldTest | 7 | Hold creation, validation |
| OrderTest | 9 | Order flow, job processing |
| ExpireHoldsTest | 6 | Hold expiration, stock restore |
| IntegrationTest | 5 | End-to-end flows |

## 🔒 Database Schema

### Products
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | string | Product name |
| total_stock | unsigned int | Available stock |
| price | decimal(10,2) | Unit price |

### Holds
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| product_id | bigint FK | Product reference |
| qty | unsigned int | Quantity held |
| status | string | pending/completed/expired |
| expires_at | timestamp | Hold expiration time |

### Orders
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| hold_id | bigint FK (unique) | Hold reference |
| status | string | pending/paid/failed |
| total_amount | decimal(10,2) | Order total |

### Webhook Logs
| Column | Type | Description |
|--------|------|-------------|
| idempotency_key | string PK | Unique webhook identifier |
| response_body | json | Cached response |
| response_status_code | int | HTTP status code |

### Pending Webhooks
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| hold_id | bigint FK (unique) | Hold reference |
| status | string | Payment status |

## 📊 Stress Testing with k6

### [Install](https://grafana.com/docs/k6/latest/set-up/install-k6/) k6
```bash
# macOS: brew install k6
# Linux: sudo apt install k6

# Run stress test
k6 run full_flow_test.js
k6 run stress_test_50.js
```

To run `stress_test_50` use `php artisan db:seed`

You will see that `10` valid responses of hold, because stock is limited to `10`, and no overselling occurs.

## 🚧 Known Limitations

1. **SQLite**: `lockForUpdate()` doesn't work with SQLite. Use MySQL/PostgreSQL for production.
2. **Queue**: For proper async processing, configure a queue driver other than `sync`.
3. **Scheduler**: Hold expiration requires scheduled task runner.

## 📝 License

MIT License

