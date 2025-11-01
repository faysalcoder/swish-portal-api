# SOP Portal REST API

A modern PHP-based RESTful API backend for the **SOP Management Portal**, built with clean architecture principles. It supports JWT authentication, role-based access control, meeting management, notices, RACI matrix, helpdesk, and more.

---

## 🚀 Features

- JWT-based Authentication (Login / Register / Password Reset)
- CRUD for Users, Wings, Sub-wings, SOPs, Rooms, Meetings, Notices, Forms
- RACI Matrix Management
- Helpdesk Request & Ticket System
- Password Recovery & Change Password Service
- Secure CORS-enabled API with JSON responses

---

## 🧩 Tech Stack

- **Language**: PHP 8.2+
- **Database**: MySQL 8+
- **Architecture**: MVC
- **Authentication**: JWT
- **Routing**: Custom lightweight router (`App\Router\Router`)
- **Mailer**: PHPMailer

---

## ⚙️ Setup Instructions

### 1. Clone Repository

```bash
git clone https://github.com/your-org/sop-portal-api.git
cd sop-portal-api
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Environment Setup

Create `.env` file at root:

```env
APP_URL=http://localhost
APP_CORS_ORIGIN=*
DB_HOST=localhost
DB_NAME=sop_portal
DB_USER=root
DB_PASS=
JWT_SECRET=your-secret-key
JWT_TTL=86400
PASSWORD_RESET_TTL=3600
MAIL_FROM_NAME=SOP Support
MAIL_FROM_ADDRESS=support@example.com
MAIL_SMTP_HOST=smtp.example.com
MAIL_SMTP_USER=user@example.com
MAIL_SMTP_PASS=secret
MAIL_SMTP_PORT=587
```

### 4. Database Setup

Import the provided SQL schema:

```bash
mysql -u root -p sop_portal < database/schema.sql
```

### 5. Run Server

```bash
php -S localhost:8000 -t public
```

Your API will now be available at: `http://localhost:8000`

---

## 🔑 Authentication Endpoints

### Register

**POST** `/api/v1/auth/register`

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "Secret@123"
}
```

**Response:**

```json
{
  "success": true,
  "message": "User registered",
  "data": { "id": 1, "name": "John Doe", "email": "john@example.com" }
}
```

### Login

**POST** `/api/v1/auth/login`

```json
{
  "email": "john@example.com",
  "password": "Secret@123"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Login success",
  "data": {
    "token": "<jwt-token>",
    "user": { "id": 1, "name": "John Doe", "email": "john@example.com" }
  }
}
```

### Forgot Password

**POST** `/api/v1/auth/forgot-password`

```json
{
  "email": "john@example.com"
}
```

**Response:**

```json
{
  "success": true,
  "message": "If that email is registered, a password reset link has been sent."
}
```

### Reset Password

**POST** `/api/v1/auth/reset-password`

```json
{
  "token": "<reset-token>",
  "password": "NewPass@123"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Password updated"
}
```

### Change Password

**POST** `/api/v1/auth/change-password`

```json
{
  "current_password": "OldPass@123",
  "new_password": "NewPass@456"
}
```

**Response:**

```json
{ "success": true, "message": "Password changed successfully" }
```

### Get Logged-in User

**GET** `/api/v1/auth/me`
Headers:

```
Authorization: Bearer <jwt-token>
```

**Response:**

```json
{
  "success": true,
  "data": { "id": 1, "name": "John Doe", "email": "john@example.com" }
}
```

---

## 👥 User Endpoints

| Method | Endpoint             | Description    |
| ------ | -------------------- | -------------- |
| GET    | `/api/v1/users`      | List all users |
| GET    | `/api/v1/users/{id}` | Get user by ID |
| POST   | `/api/v1/users`      | Create user    |
| PUT    | `/api/v1/users/{id}` | Update user    |
| DELETE | `/api/v1/users/{id}` | Delete user    |

**Example Request:**

```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "role": 2
}
```

---

## 📢 Notice Endpoints

| Method | Endpoint               | Description             |
| ------ | ---------------------- | ----------------------- |
| GET    | `/api/v1/notices`      | List all active notices |
| GET    | `/api/v1/notices/{id}` | Get notice by ID        |
| POST   | `/api/v1/notices`      | Create new notice       |
| PUT    | `/api/v1/notices/{id}` | Update notice           |
| DELETE | `/api/v1/notices/{id}` | Delete notice           |

**Example Request:**

```json
{
  "title": "Office Closed",
  "notice_type": "announcement",
  "notice_note": "Office will remain closed on Friday.",
  "valid_till": "2025-12-31 23:59:59"
}
```

---

## 🏢 Wings & Sub-wings

| Method | Endpoint                      | Description           |
| ------ | ----------------------------- | --------------------- |
| GET    | `/api/v1/wings`               | List all wings        |
| POST   | `/api/v1/wings`               | Create wing           |
| GET    | `/api/v1/subwings`            | List subwings         |
| GET    | `/api/v1/wings/{id}/subwings` | List subwings by wing |

---

## 🧾 SOPs

| Method | Endpoint            | Description   |
| ------ | ------------------- | ------------- |
| GET    | `/api/v1/sops`      | List all SOPs |
| POST   | `/api/v1/sops`      | Create SOP    |
| GET    | `/api/v1/sops/{id}` | Get SOP by ID |
| PUT    | `/api/v1/sops/{id}` | Update SOP    |
| DELETE | `/api/v1/sops/{id}` | Delete SOP    |

---

## 📅 Meetings & Rooms

| Method | Endpoint                | Description      |
| ------ | ----------------------- | ---------------- |
| GET    | `/api/v1/rooms`         | List rooms       |
| POST   | `/api/v1/rooms`         | Create room      |
| GET    | `/api/v1/meetings`      | List meetings    |
| POST   | `/api/v1/meetings`      | Schedule meeting |
| PUT    | `/api/v1/meetings/{id}` | Update meeting   |
| DELETE | `/api/v1/meetings/{id}` | Delete meeting   |

---

## 🧠 RACI Matrix

| Method | Endpoint            | Description            |
| ------ | ------------------- | ---------------------- |
| GET    | `/api/v1/raci`      | List all RACI matrices |
| POST   | `/api/v1/raci`      | Create matrix          |
| GET    | `/api/v1/raci/{id}` | Get by ID              |
| PUT    | `/api/v1/raci/{id}` | Update matrix          |
| DELETE | `/api/v1/raci/{id}` | Delete matrix          |

---

## 🛠 Helpdesk

| Method | Endpoint                    | Description       |
| ------ | --------------------------- | ----------------- |
| GET    | `/api/v1/helpdesk/requests` | List all requests |
| POST   | `/api/v1/helpdesk/requests` | Create request    |
| GET    | `/api/v1/helpdesk/tickets`  | List all tickets  |
| POST   | `/api/v1/helpdesk/tickets`  | Create ticket     |

---

## 📂 Forms

| Method | Endpoint             | Description     |
| ------ | -------------------- | --------------- |
| GET    | `/api/v1/forms`      | List all forms  |
| POST   | `/api/v1/forms`      | Create new form |
| GET    | `/api/v1/forms/{id}` | Get form by ID  |
| PUT    | `/api/v1/forms/{id}` | Update form     |
| DELETE | `/api/v1/forms/{id}` | Delete form     |

---

## 🧰 Development Notes

- All responses are JSON formatted.
- Every endpoint returns `{ success, message, data }`.
- Include `Authorization: Bearer <jwt>` in protected routes.
- Handle `OPTIONS` preflight requests for CORS.

---

## 🧪 Example CURL Requests

### Login

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "john@example.com", "password": "Secret@123"}'
```

### Create Notice

```bash
curl -X POST http://localhost:8000/api/v1/notices \
  -H "Authorization: Bearer <jwt>" \
  -H "Content-Type: application/json" \
  -d '{"title": "System Maintenance", "notice_note": "Servers will be down tonight"}'
```

---

## 🧾 License

This project is licensed under the **MIT License**.

---

## 📧 Support

For bug reports or questions, contact: `support@example.com`
