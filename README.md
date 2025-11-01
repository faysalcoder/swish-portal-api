Swish Portal API — Full Documentation
Generated: 2025-11-01 08:47:02Z
This document lists all API endpoints, HTTP method, URL, required headers, request body JSON examples, and typical responses. Paths are relative to the application base URL (for example: http://localhost/swish-portal-api/public). Ensure your .env and server routing are configured correctly.
Authentication & Common Notes
• All JSON requests must include header: Content-Type: application/json
• Protected endpoints require an Authorization header: Authorization: Bearer <JWT_TOKEN>
• Standard response shape:
{
  "success": true,
  "message": "Optional human message",
  "data": {}
}
Auth Endpoints
1) Register
POST /api/v1/auth/register
Headers: Content-Type: application/json
Request JSON:
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "Secret123!",
  "role": 2,
  "status": "active"
}
Success (201):
{
  "success": true,
  "message": "User registered",
  "data": {
    "id": 12,
    "name": "John Doe",
    "email": "john@example.com",
    "role": 2
  }
}
2) Login
POST /api/v1/auth/login
Request JSON:
{
  "email": "john@example.com",
  "password": "Secret123!"
}
Success (200):
{
  "success": true,
  "message": "Login success",
  "data": {
    "token": "eyJ...",
    "user": {
      "id": 12,
      "name": "John Doe",
      "email": "john@example.com"
    }
  }
}
3) Forgot Password
POST /api/v1/auth/forgot-password
Request JSON:
{
  "email": "john@example.com"
}
Response: generic message (200) — server emails reset link if address exists.
{
  "success": true,
  "message": "If that email is registered, a password reset link has been sent.",
  "data": null
}
4) Reset Password
POST /api/v1/auth/reset-password
Request JSON:
{
  "token": "RAW_TOKEN_FROM_EMAIL",
  "password": "NewPassword123!"
}
Success (200):
{
  "success": true,
  "message": "Password updated",
  "data": null
}
Common error (400): invalid/expired token
{
  "success": false,
  "message": "Invalid or expired token",
  "data": null
}
5) Change Password (authenticated)
POST /api/v1/auth/change-password
Headers: Authorization: Bearer <token>
Request JSON:
{
  "current_password": "OldPass123",
  "new_password": "NewPass123!"
}
Success (200):
{
  "success": true,
  "message": "Password changed successfully",
  "data": null
}
Unauthorized (401) if token missing/invalid.
6) Get Current User
GET /api/v1/auth/me
Headers: Authorization: Bearer <token>
Success (200):
{
  "success": true,
  "message": "",
  "data": {
    "id": 12,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
Users
General note: user management endpoints follow RESTful patterns and require authorization for protected operations.
1) List Users
GET /api/v1/users
Headers: Authorization: Bearer <token>
Success (200):
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Alice",
      "email": "alice@example.com"
    }
  ]
}
2) Create User
POST /api/v1/users
Headers: Authorization: Bearer <token>, Content-Type: application/json
Request JSON:
{
  "name": "Alice",
  "email": "alice@example.com",
  "password": "Pass123!",
  "role": 2,
  "status": "active"
}
Success (201): created user object
3) Show User
GET /api/v1/users/{id}
Example success:
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Alice",
    "email": "alice@example.com"
  }
}
4) Update User
PUT /api/v1/users/{id}
Request JSON:
{
  "name": "Alice Smith",
  "designation": "Engineer"
}
Success (200): updated user object
5) Delete User
DELETE /api/v1/users/{id}
Success (204): no content
Wings & Sub-wings
Wings represent high-level groups (projects, departments). Sub-wings belong to wings.
Wings
GET /api/v1/wings — list wings
POST /api/v1/wings — create wing (JSON)
{
  "name": "Product",
  "description": "Product wing"
}
Sub-wings
GET /api/v1/subwings
POST /api/v1/subwings
GET /api/v1/subwings/{id}
PUT /api/v1/subwings/{id}
DELETE /api/v1/subwings/{id}
List subwings for a wing: GET /api/v1/wings/{id}/subwings
Locations
GET /api/v1/locations — list
POST /api/v1/locations — create
GET /api/v1/locations/{id} — show
PUT /api/v1/locations/{id} — update
DELETE /api/v1/locations/{id} — delete
Request example to create:
{
  "name": "Head Office",
  "address": "123 Main St",
  "city": "Dhaka"
}
SOPs & Files
SOP endpoints store process documents and related files. File upload endpoints accept multipart/form-data.
GET /api/v1/sops — list SOPs
POST /api/v1/sops — create SOP (JSON)
{
  "title": "Onboarding SOP",
  "description": "New hire process",
  "created_by": 1
}
GET /api/v1/sops/{id} — show SOP
PUT /api/v1/sops/{id} — update SOP
DELETE /api/v1/sops/{id} — delete SOP
Files: POST /api/v1/sops/{id}/files (multipart/form-data) — field "file" upload
GET /api/v1/sops/{id}/files — list files
GET /api/v1/sop-files/{id} — download file
DELETE /api/v1/sop-files/{id} — delete file
Rooms & Meetings
Rooms: meeting room resources. Meetings scheduled in rooms.
Rooms endpoints:
{
  "name": "Room A",
  "location_id": 1,
  "capacity": 8
}
Meetings endpoints:
{
  "title": "Weekly Sync",
  "room_id": 1,
  "start_time": "2025-11-03 10:00:00",
  "end_time": "2025-11-03 11:00:00",
  "created_by": 2
}
Meeting status endpoints: POST /api/v1/meetings/{id}/status (add status), GET /api/v1/meetings/{id}/status (list statuses)
Notices & Forms
Notices are announcements. Forms are user-submitted forms.
Create notice — POST /api/v1/notices (JSON):
{
  "title": "Server Maintenance",
  "notice_type": "note",
  "notice_note": "Maintenance on 2025-11-05",
  "valid_till": "2025-11-08 23:59:59"
}
List notices — GET /api/v1/notices (only active by valid_till)
Show/Update/Delete — standard RESTful endpoints: GET/PUT/DELETE /api/v1/notices/{id}
RACI
RACI Matrices endpoints store roles and assignments for tasks.
GET /api/v1/raci — list matrices
POST /api/v1/raci — create matrix (JSON)
GET /api/v1/raci/{id} — show
PUT /api/v1/raci/{id} — update
DELETE /api/v1/raci/{id} — delete
Roles: GET /api/v1/raci/{id}/roles, POST /api/v1/raci/roles, DELETE /api/v1/raci/roles/{id}
Helpdesk
Requests (user-submitted issues) and tickets (internal tracking).
Endpoints: GET/POST /api/v1/helpdesk/requests, GET /api/v1/helpdesk/requests/{id}, DELETE /api/v1/helpdesk/requests/{id}
Tickets: GET/POST/GET{ id }/PUT/DELETE /api/v1/helpdesk/tickets
Responses & Errors
Standard: { "success": boolean, "message": string, "data": ... }
Errors: example 400/422/401/500 responses:
{
  "success": false,
  "message": "Invalid input",
  "data": null,
  "error": "Details..."
}
Demo JSON Data (example seed)
{
  "users": [
    {
      "id": 1,
      "name": "Admin User",
      "email": "admin@example.com",
      "role": 1,
      "status": "active",
      "password": "hashed_password"
    },
    {
      "id": 2,
      "name": "John Doe",
      "email": "john@example.com",
      "role": 2,
      "status": "active",
      "password": "hashed_password"
    }
  ],
  "wings": [
    {
      "id": 1,
      "name": "Operations",
      "description": "Operations wing"
    }
  ],
  "subwings": [
    {
      "id": 1,
      "wing_id": 1,
      "name": "Onboarding"
    }
  ],
  "locations": [
    {
      "id": 1,
      "name": "Head Office",
      "address": "123 Main St",
      "city": "Dhaka"
    }
  ],
  "sops": [
    {
      "id": 1,
      "title": "Onboarding SOP",
      "description": "Steps to onboard",
      "created_by": 1,
      "created_at": "2025-10-01 09:00:00"
    }
  ],
  "rooms": [
    {
      "id": 1,
      "name": "Room A",
      "location": "Floor 1",
      "capacity": 8
    }
  ],
  "meetings": [
    {
      "id": 1,
      "title": "Weekly Sync",
      "room_id": 1,
      "start_time": "2025-11-03 10:00:00",
      "end_time": "2025-11-03 11:00:00",
      "created_by": 2
    }
  ],
  "notices": [
    {
      "id": 1,
      "title": "Server Maintenance",
      "notice_type": "note",
      "notice_note": "Maintenance on 2025-11-05",
      "timestamp": "2025-11-01 10:00:00",
      "valid_till": "2025-11-08 23:59:59",
      "user_id": 1
    }
  ],
  "raci": [
    {
      "id": 1,
      "title": "Deployment RACI",
      "description": "RACI for deployment"
    }
  ],
  "helpdesk_requests": [
    {
      "id": 1,
      "user_id": 2,
      "subject": "Cannot access SOP",
      "description": "I get 403 when trying to download."
    }
  ],
  "user_recovery_tokens": [
    {
      "id": 1,
      "user_id": 2,
      "token_hash": "HMAC_HASH",
      "status": "unused",
      "validity": "2025-11-01 12:00:00"
    }
  ]
}
