# WhatsApp pre-screening templates (Meta Business Manager)

Create these in **Meta Business Suite → WhatsApp Manager → Message templates** for the same WABA as `WHATSAPP_PHONE_NUMBER_ID`.

## 1. Invite — `xander_prescreening_invite`

Used when admin sends a pre-screening invite from the dashboard. Student must reply **START** (button or text) to begin.

| Field | Value |
|--------|--------|
| **Name** | `xander_prescreening_invite` |
| **Category** | Utility |
| **Language** | English (US) → code `en_US` (or `en` if you use that in `.env`) |

**Body**

```
Hello {{1}}, Xander Global Scholars invites you to complete Quick Pre-Screening on WhatsApp.

Reply START to begin (15 questions and documents). Type CANCEL to stop.
```

**Variables:** 1 — student name (e.g. `John`)

**Buttons (recommended — Quick reply)**

| Button | Payload / text |
|--------|----------------|
| START | `START` |
| CANCEL | `CANCEL` |

If buttons are not approved yet, students can type `START` or `CANCEL` as plain text.

**.env**

```env
WHATSAPP_PRESCREENING_INVITE_TEMPLATE=xander_prescreening_invite
WHATSAPP_PRESCREENING_INVITE_TEMPLATE_LANG=en_US
```

---

## 2. Submission received — `xander_prescreening_received`

Sent automatically after the student finishes all questions and documents on WhatsApp.

| Field | Value |
|--------|--------|
| **Name** | `xander_prescreening_received` |
| **Category** | Utility |
| **Language** | English → `en` |

**Body**

```
Hello {{1}}, thank you for your pre-screening with Xander Global Scholars.

Your reference is {{2}}. Our team will review your answers and contact you soon.
```

**Variables:** 1 — student name, 2 — reference (e.g. `PS-A1B2C3D4`)

**.env** (optional override)

```env
WHATSAPP_PRESCREENING_RECEIVED_TEMPLATE=xander_prescreening_received
WHATSAPP_PRESCREENING_RECEIVED_TEMPLATE_LANG=en
```

---

## When pre-screening runs on WhatsApp (routing)

| Situation | Bot behaviour |
|-----------|----------------|
| Student types **prescreening** / **pre-screening** / **prescreen** (no session) | Starts 15-question flow |
| Admin sent **invite** template; student taps **START** | Continues invited flow |
| Student in middle of questions or document upload | All messages handled by pre-screening |
| Student types **CANCEL** | Ends session |
| Normal chat (**Hello**, visa questions) | FAQ / AI chatbot (not pre-screening) |

Webhook: `https://xanderbot.site/api/webhook/meta`  
Pre-screening API (VPS): `https://xanderbot.site/api/prescreening/inbound`

After creating templates, wait for **Approved** status before testing invites.
