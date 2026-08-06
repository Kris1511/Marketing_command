# Authentication Implementation Documentation (React SPA + Laravel Sanctum)

## Overview
The **Marketing Command** application uses **Laravel Sanctum Token Authentication** to secure all API endpoints between the React SPA frontend and the Laravel 11 backend.

---

## 1. Demo Credentials

You can log in to the dashboard using either of the seeded accounts:

| User Type | Email | Password | Role |
| :--- | :--- | :--- | :--- |
| **Admin User** | `demo@example.com` | `password123` | `admin` |
| **Manager User** | `manager@example.com` | `password123` | `manager` |

---

## 2. Authentication Architecture

```
[ LoginPage.jsx ] --(POST /v1/login)--> [ Laravel Route ] --> Validates credentials
                                                                       │
[ LocalStorage ] <--(Returns Token & User)----------------─────────────┘
  auth_token
       │
[ axiosInstance.js Interceptor ]
  Attaches `Authorization: Bearer {token}` to all requests
       │
[ Sanctum Middleware: auth:sanctum ] --> Validates token on protected routes
```

---

## 3. Key Files & Components

### Backend (Laravel):
- **`routes/api.php`**: Contains public auth endpoints (`/v1/login`, `/v1/register`, `/v1/health`) and Sanctum protected endpoints (`/v1/user`, `/v1/logout`, `/v1/overview`, etc.).
- **`database/seeders/UserSeeder.php`**: Seeds `demo@example.com` and `manager@example.com` demo accounts.
- **`bootstrap/app.php`**: Configured with `$middleware->statefulApi()`.

### Frontend (React):
- **`src/api/axiosInstance.js`**: Custom Axios instance attaching `Authorization: Bearer {token}` header to requests and handling `401` unauthorized responses.
- **`src/context/AuthContext.jsx`**: Global authentication state manager (`user`, `token`, `isAuthenticated`, `login()`, `logout()`, `register()`).
- **`src/hooks/useAuth.js`**: React hook for accessing auth context.
- **`src/components/ProtectedRoute.jsx`**: Enforces authentication on private dashboard routes.
- **`src/pages/LoginPage.jsx`**: Login page UI with pre-fill demo buttons and error handling.
- **`src/components/Sidebar.jsx`**: Displays logged-in user details and Logout action button.

---

## 4. API Auth Endpoint Specifications

### Public Endpoints:
- `POST /api/v1/login`
  - Body: `{ "email": "demo@example.com", "password": "password123" }`
  - Response: `{ "success": true, "token": "<sanctum-plain-text-token>", "user": { ... } }`

- `POST /api/v1/register`
  - Body: `{ "name": "User Name", "email": "user@example.com", "password": "secretpassword" }`
  - Response: `{ "success": true, "token": "<sanctum-plain-text-token>", "user": { ... } }`

### Protected Endpoints (Requires `Authorization: Bearer {token}`):
- `GET /api/v1/user` — Returns current authenticated user object.
- `POST /api/v1/logout` — Revokes active Sanctum API token.
- `GET /api/v1/dashboard/metrics`
- `GET /api/v1/workspaces`
- `GET /api/v1/leads`
- `GET /api/v1/notifications`
- `GET /api/v1/team`
