# Changelog

All notable changes to the Sendmux Sending API Extension will be documented in this file.

## [0.2.0] - 2026-03-25

### Fixed
- **Error parsing** — now correctly reads Sendmux API error envelope (`error.message`, `error.code`, `error.param`). Previous version read `error` as a flat string, which logged "Array" instead of the actual error message on any API failure.

### Changed
- **Username field hidden** — removed from form entirely since only the API Key (Bearer token) is used for authentication
- **Field label** — password field relabeled from "Sending API Key" to "API Key"
- **Help text** — added step-by-step Sending Key creation guide (dashboard → API Keys → Create API Key → Sending Key → provider scope)
- **Info modals** — backend and customer views now include numbered setup instructions with links to app.sendmux.ai
- **Extension name** — renamed from "Sendmux Email Proxy API" to "Sendmux Sending API"
- **Extension description** — rewritten with value framing (costs just a few cents per 1,000 emails) and link to pricing
- **Controller class** — renamed from `SendmuxCustomExtFrontendDswhController` to `SendmuxExtFrontendDswhController` (removed leftover "Custom")
- **Docblock comments** — updated consistently to "Sendmux Sending API" throughout

## [0.1.0] - 2026-03-25

### Added
- Initial public release for transparency
- Email sending integration with Sendmux Sending API
- Automatic bounce handling via webhooks
- Automatic complaint/abuse report handling via webhooks
- Support for email attachments with automatic MIME type detection
- Custom header support (X- prefixed headers)
- Return-path generation for campaign and transactional emails
- Webhook endpoint for processing delivery events
- Support for both single and batch webhook event formats

### Features
- **Email Delivery**: Send emails via Sendmux Sending API with full HTML and plain text support
- **Bounce Processing**: Automatic detection and logging of hard bounces, soft bounces, and delivery failures
- **Complaint Processing**: Automatic handling of abuse and fraud reports
- **Subscriber Management**: Automatic blacklisting on hard bounces and complaints
- **Duplicate Prevention**: Built-in duplicate event detection to prevent reprocessing
- **Event Types Supported**:
  - `delivery.dsn-perm-fail` - Hard bounces
  - `delivery.dsn-temp-fail` - Soft bounces
  - `delivery.failed` - Internal delivery failures
  - `incoming-report.abuse-report` - Abuse complaints
  - `incoming-report.fraud-report` - Fraud reports

### Technical Details
- Minimum MailWizz version: 2.0.0
- PHP strict types enabled
- Uses GuzzleHTTP for API requests
- Bearer token authentication with API key
- Webhook URL format: `https://yourdomain.com/dswh/sendmux-api/{server_id}`
