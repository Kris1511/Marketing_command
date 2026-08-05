# Digital Marketing Management, CRM & Reporting Dashboard

A professional, responsive front-end prototype based on the supplied requirements document.

## Open and test

Open `index.html` in Chrome, Edge, or Firefox. The dashboard works immediately with built-in sample data and simulated API responses.

## Main modules

- Multi-client workspace management with isolated client data
- Unified marketing performance dashboard
- Selective Facebook, Instagram, YouTube, Google Analytics, Search Console, and Google Business connections
- Central content publishing and scheduling form
- Scheduled content calendar
- CRM lead list, assignment, stages, filters, and pipeline
- Custom report preview with PDF/print and Excel-compatible CSV download
- Notifications and reminder preferences
- Team role overview
- API connection status and test actions

## Centralized API handling

### `js/api-config.js`
Change API base URL, endpoint paths, platform API versions, timeout, tenant header, and sync settings here.

### `js/api-service.js`
All network requests pass through this file. It handles:

- Authentication token headers
- Client workspace / tenant header
- Request timeout
- JSON payloads
- Common error handling
- Mock responses for testing
- API methods grouped by module

No UI file calls `fetch()` directly.

## Connect a real backend

1. Open `js/api-config.js`.
2. Change `mockMode` to `false`.
3. Set `apiBaseUrl` to your backend URL.
4. Match the endpoint paths to your backend routes.
5. Implement secure backend OAuth callback routes for social and Google integrations.
6. Return the JSON structures expected by the methods in `js/api-service.js`.

## Security note

Do not place Meta, Google, YouTube, LinkedIn, or X client secrets and refresh tokens in browser files. Store them encrypted on the backend. The dashboard should receive only short-lived application access tokens.

## Suggested production backend

- REST API or GraphQL gateway
- Tenant isolation using client workspace ID
- PostgreSQL for clients, leads, posts, reports, and normalized analytics
- Redis/queue workers for scheduled publishing and analytics synchronization
- Object storage for post media and report assets
- OAuth credential vault with encryption
- Audit log for publishing, CRM updates, and account connections
