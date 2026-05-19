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

## Flow (not mixed with FAQ bot)

1. **Web admin** sends invite (Pre-screening page → student WhatsApp number).
2. Student receives template `xander_prescreening_invite` and taps **START**.
3. Student answers 15 questions + documents **on WhatsApp only** (dedicated handler).
4. On **Finish** → row in `prescreening_submissions` with `source = whatsapp` → shows in the **same web list** as form submissions.
5. **Normal WhatsApp chat** (Hello, visa questions) → FAQ bot only. Typing "prescreening" does **not** start a form.

| Situation | Handler |
|-----------|---------|
| **Hello**, general questions | FAQ / AI bot |
| No web invite; student types random text | FAQ bot |
| Web invite sent; student taps **START** | Pre-screening Q&A |
| Mid-flow questions / documents | Pre-screening only |
| **CANCEL** during invite/flow | Ends pre-screening session |

Webhook (all traffic): `https://xanderbot.site/api/webhook/meta`  
Pre-screening logic (when invited): forward URL in `.env` (cPanel or `/api/prescreening/inbound`)

After creating templates, wait for **Approved** status before testing invites.
