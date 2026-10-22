# Administrator Manual

**Non-Teaching Recruitment Portal, ABV-IIITM Gwalior**

This manual is for the **Super Administrator** and **Recruitment Administrators** of the Recruitment Section. Scrutiny officers and Screening Committee members have their own manuals.

---

## 1. Roles and access

| Role | What it is for |
|---|---|
| **Super Administrator** | Holds every permission automatically. Sees every advertisement and application. Creates users, sets permissions, and configures the portal. |
| **Recruitment Administrator** | Manages the advertisements they are assigned to (on the advertisement's **People** tab): receives applications, verifies fee, documents and endorsement, assigns scrutiny, manages referees, forwards to the committee. Sees only applications of their assigned advertisements. |

How to get access:

1. The Super Administrator creates your account on **Users** (the first Super Administrator account is created by the technical team at installation).
2. You receive a password-reset email. Click the link and set your own password. If the link has expired, ask the Super Administrator to click **Send password reset link** for you.
3. Sign in at the portal's **Sign in** page. Staff land on the **Dashboard**.

What you can do depends on the permissions given to your role on **Roles & permissions** (see section 3.8). Buttons you are not permitted to use do not appear.

### Menu (left sidebar)

**Dashboard**, **Applications**, **My scrutiny queue**, **Screening Committee**, **Reports & exports**; configuration items **Advertisements**, **Recruitment cycles**, **Checklists**, **Document types**, **Referee form**, **Committee form**, **Email templates**, **Settings**; administration items **Users**, **Roles & permissions**, **Email delivery log**, **Audit trail**. You see only the items your permissions allow.

## 2. The process at a glance

1. **Prepare** reference data: cycles, document types, checklist templates, referee and committee forms, email templates, settings, users.
2. **Create an advertisement** with its posts, fees, document requirements, scrutiny workflow, checklists and people; then **Publish**.
3. Candidates apply. At their deadline the advertisement closes automatically.
4. For each submitted application: **Mark received**, verify **fee**, **documents** and **endorsement**, and **Assign for scrutiny**.
5. Scrutiny officers record checklist verdicts and stage reports; the **decision stage** records the outcome.
6. Referees respond by email link. Manage invitations from the **Referees** tab.
7. **Forward to Screening Committee** applications that are recommended.
8. The committee evaluates and records its decision.
9. Use **Reports & exports**, the **Email delivery log** and the **Audit trail** throughout.

---

## 3. Prepare reference data

### 3.1 Recruitment cycles

**Recruitment cycles** → **New cycle**. Enter **Name**, **Year**, **Description**, and tick **Active (offered when creating advertisements)**. A cycle that has advertisements cannot be deleted.

### 3.2 Document types

**Document types** lists every kind of document candidates can upload, with **Formats** (PDF, JPEG, PNG), **Max size** (20 KB to 20 MB) and the **Wizard step** where the upload box appears. Click **New document type** or **Edit**.

- Uploads are checked on the server against these formats and sizes. The portal also checks that each file's contents match its type, and refuses PDFs containing scripts, embedded files or launch actions.
- A type with **Wizard step** "— None —" appears on the candidate's Check List step.
- Do not delete types in use; untick **Active** instead. Avoid changing the code of a type in use.

### 3.3 Checklist templates

**Checklists** → **New checklist** (or **Copy** an existing one). For each item set **Label**, **Code**, **Section**, an optional **Document type**, verification instructions, and tick as needed:

- **Mandatory** — scrutiny cannot report until a verdict is recorded on it.
- **Reason if not verified** — a discrepancy reason is required for a "Not verified" verdict.
- **Applicant visible (Step 17)** — the item also appears on the candidate's Check List.

A standard template, "Standard non-teaching checklist (Form page 5)", is provided with the Form page 5 items plus scrutiny-only items. A template already used in scrutiny shows **In use**: edits apply to verdicts recorded from then on; deleting it archives it instead. Archived templates are read-only; copy them to make a new version.

### 3.4 Referee form and Committee form

**Referee form** and **Committee form** each hold one or more form templates. For each template set the name, **Active**, introduction (referee) or description (committee), the **Good-faith statement** (referee only), the recommendation options (2 to 10), and up to 40 questions. Answer types include Yes / No, rating (with labels for each point), choice, and short or long text; tick **Required** where needed.

Each submitted recommendation or evaluation keeps a snapshot of the wording it was answered against, so editing a form never alters responses already received.

### 3.5 Email templates

**Email templates** lists every automatic email (verification, password reset, submission confirmation, acknowledgment, reopening, referee invitation, reminder and verification code, notices to administrators, clarification request and receipt, scrutiny assignment and completion, decision notification, deadline reminder).

1. Select a template, edit **Subject** and **Body** (Markdown: \*\*bold\*\*, \*italic\*, [link text](url)).
2. Insert placeholders with the buttons shown; unknown placeholders are flagged and would be left blank.
3. Check the **Preview** with sample values, then click **Save template** (or **Revert**).

**Caution:** while a template is not **Active**, that email is not sent at all. For referee invitations and reminders the invitation is then marked **Delivery failed** and administrators are alerted; other emails are skipped silently. Never deactivate the referee invitation, verification or password reset templates during a live recruitment.

### 3.6 Settings

**Settings** groups: **Institute** (name, address, recruitment email and phone shown in emails and PDFs), **Candidate declaration**, **Privacy notice**, **Referees**, **Clarifications**, **Records retention**, **Uploads**, **Workflow**, **Applicants**. Click **Save settings** after changes.

- **Declaration version:** change it whenever the declaration text changes materially. Every candidate with an unsubmitted or reopened application must then accept the declaration again. Changing only the text, without the version, does not ask anyone again.
- **Default clarification response time (days):** used when a clarification request has no due date.
- **Require all referee recommendations before forwarding to the committee:** when ticked, applies to every advertisement.
- **Maximum open draft applications per candidate.**

### 3.7 Users

**Users** → **New staff user**. Enter **Name**, **Email**, **Mobile**, **Designation**, tick **Roles**, and click **Create and send link**. No password is set here; the user receives a link to set their own.

- Untick **Active** to stop a user signing in; they are signed out immediately.
- **Send password reset link** re-sends a set-password email.
- A **Locked** badge shows a user locked out after repeated wrong passwords; it clears after 15 minutes or after a password reset.
- You cannot remove your own Super Administrator role or deactivate yourself.

### 3.8 Roles & permissions

**Roles & permissions** shows each permission (rows) against each staff role (columns). Tick or untick, then click **Save** under the role. Permissions marked **Sensitive** give access to confidential material or logs. Changes are recorded in the audit trail. Data access is still limited to a user's assigned advertisements, teams and committees.

Default permissions on a new installation:

| Permission (as shown) | Held by default |
|---|---|
| Create and manage advertisements, posts and vacancies | Super Administrator only |
| Manage checklists, document types, referee and evaluation forms | Super Administrator only |
| Edit email templates · Edit system settings · Create users, assign roles, teams and committees | Super Administrator only |
| View audit logs and access logs (sensitive) | Super Administrator only |
| View email delivery logs | Recruitment Administrator |
| View applications in assigned advertisements | Recruitment Administrator |
| Verify fee, documents and employer endorsement | Recruitment Administrator, Scrutiny User |
| Assign applications for scrutiny and forward to committee | Recruitment Administrator |
| Reopen submitted applications for correction | Super Administrator only |
| Send clarification requests to candidates | Recruitment Administrator, Scrutiny User |
| View application history and audit trail | Recruitment Administrator |
| Open and download applicant documents (sensitive) | Recruitment Administrator, Scrutiny User, Committee Member |
| View referee invitation and recommendation status | Recruitment Administrator, Scrutiny User, Committee Member |
| Read confidential referee recommendations (sensitive) | Recruitment Administrator, Committee Member |
| Resend, remind, revoke, replace and extend referee invitations | Recruitment Administrator |
| Grant referee exceptions and record amendments | Super Administrator only |
| Perform scrutiny on assigned applications | Scrutiny User, Recruitment Administrator |
| Evaluate applications assigned to the committee | Committee Member |
| Record the committee decision (in addition to the chair) | Super Administrator only |
| View recruitment reports | Recruitment Administrator |
| Export consolidated application data (sensitive) | Recruitment Administrator |
| Export confidential referee recommendation content (sensitive) | Super Administrator only |

---

## 4. Set up and publish an advertisement

**Advertisements** → **New advertisement**. Fill in **Details** and click **Create draft advertisement**; the other tabs then become available.

### 4.1 Details tab

- **Advertisement:** recruitment cycle, advertisement number, publication date, title, department, **Applications open** and **Applications close** (submissions are refused after the closing moment, Indian time), **Reference number prefix** (submitted applications are numbered PREFIX/00001, …), summary, general instructions, reservation details, age relaxation rules.
- **Application fee and payment:** **Default fee (₹)** and exemptions by category or condition (SC, ST, PWD, FEMALE, TRANSGENDER, ESM, OBC, EWS or other). When several exemptions match a candidate, the lowest amount is charged. Add **Fee rules (as shown to candidates)**, **Payment instructions**, and tick **Accepted payment methods**.
- **Referees:** **Referees required**, **Response deadline (days)** after invitation, **Reminders after (days)** (comma-separated, counted from the invitation), **Final reminder (days before deadline)**, **Maximum reminders**, **Recommendation form**, **Recommendations mandatory before committee**, and **Referees confirm a one-time email code**. Set **Referees required** to 0 when the recruitment rules need no referees: the Referees step is then skipped for candidates.
- **Employer endorsement and Screening Committee:** **Employer endorsement** (Not applicable / Optional / Required for in-service candidates), endorsement instructions, **Committee evaluation mode** (Individual or Collective), **Evaluation form**.
- **Candidate options, retention and contact:** **Candidates may withdraw a submitted application**, **Candidates may upload documents in reply to clarification requests**, **Retention (years)** (counted from the closing date; leave blank for the system default. When it ends, the advertisement is archived and flagged for disposal, and Super Administrators are emailed. Nothing is deleted automatically: disposal is a separate, authorised step carried out by the system administrator — see the Operations guide), contact email and phone.

After the first submission, the advertisement number, reference prefix and number of referees can no longer be changed.

### 4.2 Posts tab

Click **Add post**. Enter post code, name, department, **Status** (Open / Closed), group, pay level and scale, **Total vacancies**, and **Category-wise vacancies** (the portal warns if they do not add up). Under **Eligibility (as published)** give age limits, the date age is reckoned on, relaxation, and essential and desirable qualifications, experience and other conditions; these appear on the public page.

**Eligibility criteria** are optional structured rules (minimum percentage, minimum experience in months, maximum age, qualification level, or custom manual check) marked Essential or Desirable. They are shown to scrutiny officers as an advisory aid and **never reject a candidate automatically**.

**Post-level overrides** let a post have its own fee schedule or endorsement rule.

### 4.3 Advertisement PDFs tab

Upload the official advertisement (PDF, up to 20 MB). Tick **Show on the public advertisement page** to let candidates download it once published; otherwise it is internal.

### 4.4 Document requirements tab

**Add requirement** → choose **Document type**, **Post** (or All posts), **Required when** (Always, Category other than UR, Candidate with disability, Ex-serviceman, In-service candidate, Candidate has employment history, Candidate disclosed a case or proceeding, Application fee payable), tick **Mandatory** if the application cannot be submitted without it, and add instructions. Conditions are evaluated from the candidate's own answers.

### 4.5 Scrutiny workflow tab

Define up to 8 stages in order. For each stage give a code, name and **Performed by** role (Scrutiny User, Recruitment Administrator or Super Administrator). Exactly one stage must be the **Decision** stage, and it must be last. Leave **Same reviewer allowed** unticked to keep separation of duties: an officer who reported on an earlier stage of an application cannot be assigned to, or act on, that stage. Stages with assignments cannot be removed. Click **Save workflow**.

### 4.6 Checklists tab

**Assign checklist** → choose a template and tick posts (none ticked = whole advertisement). An application is checked against every template for the whole advertisement plus those for its post.

### 4.7 People tab (requires user management permission)

- **Recruitment Administrators:** tick who manages this advertisement and click **Save administrators**. These users also receive administrator notices (new submissions, referee responses, failed referee emails, scrutiny reports). If none is assigned, notices go to the Super Administrator.
- **Scrutiny teams:** **Add team**, choose a post (or All posts) and members (Scrutiny Users). Team members can view the team's applications; they can act only where assigned.
- **Screening Committees:** **Add committee**, choose a post (or All posts), evaluation mode and form (or the advertisement default), tick members and choose the **Chair**. A committee with applications cannot be deleted.

### 4.8 Status tab: publish, suspend, close, archive

The tab lists **Before this advertisement can be published:** problems: add at least one post; closing date after opening date; a scrutiny workflow with a decision stage; the referee form chosen (if referees are required); at least one payment method; a checklist assigned.

| Action | Effect |
|---|---|
| **Publish** | Visible on the public portal; candidates can apply between the opening and closing dates. |
| **Suspend** | Stays visible but no new applications or submissions are accepted until published again. |
| **Close** | No further applications. Scrutiny and committee work continue. Happens automatically once the closing date passes. |
| **Archive** | Read-only. Cannot be undone. |

Each change asks for a reason, recorded in the audit trail. A draft with no applications can be deleted from the Advertisements list.

---

## 5. Manage applications

### 5.1 Dashboard and the Applications list

The **Dashboard** shows counts (Submitted, Fee verification pending, Open clarifications, Referees pending, Referee emails failed, Recommended by scrutiny, Failed emails (7 days) and others), charts by scrutiny status and post, open advertisements and your notifications. Click a card to open the matching list.

**Applications** lists applications in your scope. Use **Search** (name, reference no., email or mobile), the advertisement, post, category, gender and date filters, and **More filters** for cycle, department, reference no. and each status. Click a reference or name to open the application profile.

### 5.2 The application profile

Tabs: **Overview**, **Application** (the last submitted version), **Documents**, **Fee**, **Referees**, **Scrutiny**, **Clarifications**, **Endorsement**, **Committee**, **History** and **Audit** (if permitted). The **PDF** button downloads the submitted version; each version is listed in **Submitted versions**.

### 5.3 Mark an application received

On a newly submitted application click **Mark received by Recruitment Section** → **Mark received**. Scrutiny status becomes **Received** and the candidate is emailed an acknowledgment (confirming receipt only).

### 5.4 Verify the fee

1. Open the **Fee** tab and compare the **UTR / DD number**, **Transaction date** and **Amount paid / due** with the bank statement. A "Short by" warning appears if the amount is less than due.
2. Under **Record fee verification** choose **Verified**, **Rejected** (remarks required) or **Pending**, then **Save verification**.
3. Every verdict is kept in **Verification history**. The candidate sees the resulting fee status on their status page; for a rejection, your remarks are shown to them under the status (the same applies to employer endorsement). Write remarks accordingly. If the candidate needs to submit something, send a clarification request.

### 5.5 Verify a document

1. On the **Documents** tab click **Open** (view) or **Download**. Every view and download is logged.
2. Click **Verify** → choose **Verified**, **Deficient** (remarks required) or **Pending** → **Save**.
3. The overall document status becomes **Deficient** if any document is deficient, **Verified** when all are verified, otherwise **Pending**. The file itself is never altered.

### 5.6 Verify the employer endorsement

On the **Endorsement** tab review Section 32 and the uploaded endorsement. Under **Record endorsement verification** choose **Verified**, **Rejected** (remarks required) or **Pending** → **Save verification**.

### 5.7 Assign for scrutiny

- **One application:** **Assign for scrutiny** → choose **Stage** and **Officer** (only users holding that stage's role are listed) → **Assign**.
- **Several:** on **Applications** tick the rows (all from one advertisement) → **Assign for scrutiny**.

The officer is emailed. Re-assigning a stage to someone else withdraws the earlier officer's assignment. The portal refuses an officer who reported on an earlier stage of the same application unless the stage allows the same reviewer.

### 5.8 Reopen an application for correction

Requires the reopen permission (Super Administrator by default). Only a **Submitted** application can be reopened.

1. Click **Reopen for correction**.
2. Enter the **Reason (sent to the candidate)**, an optional **Correction window ends** date and time, and tick the **Sections the candidate may change** (none ticked = all).
3. Click **Reopen application**. The candidate is emailed.

The current submission stays on file. When the candidate resubmits, a new version is created; the **Reopenings for correction** table records each reopening. If the candidate corrected a referee's email, a replacement invitation is sent automatically.

### 5.9 Manage referees

The **Referees** tab shows each referee with **Invitation status** (Not sent, Queued, Email sent, Delivery failed; plus "Opened by referee" and reminder count) and **Recommendation status** (Not invited, Pending, Completed, Expired, Revoked, Replaced). Delivery and completion are tracked separately.

Invitations are sent automatically on submission and expire at the end of the day that is the configured number of days later. Reminders are sent automatically to the advertisement's schedule, at most one a day.

Under **Manage**:

| Action | What happens |
|---|---|
| **Resend invitation** | The same link is emailed again; deadline unchanged. Not possible once expired. |
| **Send reminder** | Reminder email with the existing link; reminder count increases. Not possible once expired. |
| **Extend deadline** | Sets a new deadline (a future date). The same link works again. |
| **Revoke** | The link stops working immediately. Reason required. |
| **Issue replacement** | Withdraws any live link and emails a new one, optionally to a **Corrected email**. Reason required. Use this when an email bounced. |
| **Invite** | Appears only for a referee never invited. |

A completed recommendation cannot be revoked or replaced.

**Exceptions** (Super Administrator by default): **Allow referee email exception** lets the candidate's referees share an email address; **Waive referee requirement** records that missing recommendations will not hold the application back (referee status becomes **Exception**). Each needs a note or reason and is audited.

**Read a recommendation:** for a **Completed** row click **View** or the PDF button (needs the confidential-recommendations permission). The viewer is marked "CONFIDENTIAL — not visible to the applicant. Your access is logged." It shows any differences from what the candidate entered and a content hash.

**Amend a recommendation** (exceptions permission): in the viewer click **Amend recommendation**, choose the field, enter the amended value and reason, and click **Record amendment**. The original stays unchanged; the amendment is shown alongside it and in the PDF.

### 5.10 Clarifications

Clarifications are requested and resolved from the scrutiny workspace (see the Scrutiny Officer Manual). The **Clarifications** tab here shows each request, the candidate's responses and attached documents.

### 5.11 Forward to the Screening Committee

On **Applications** tick the applications (one advertisement) → **Forward to Screening Committee** → choose the committee → **Forward**. The portal refuses any application that:

- is outside the committee's advertisement or post;
- was not **Recommended** at the scrutiny decision stage;
- is still awaiting referee recommendations, where recommendations are mandatory for the advertisement or the system setting requires them (complete or **Exception** is accepted).

The Super Administrator may tick **Forward without the normal prerequisites** and give a reason; this is audited.

### 5.12 Withdrawn applications

A candidate may withdraw if the advertisement allows it and no scrutiny outcome or committee status exists. The profile shows "Withdrawn by the candidate" with any reason. Pending referee invitations are revoked automatically.

---

## 6. Reports, exports and logs

### 6.1 Reports & exports

Choose a report: **Summary reports** (totals by advertisement, post, category, gender, qualification, experience, vacancy statistics), **Application lists** (fee status, missing documents, incomplete, scrutiny pending and outcome, referee pending and completed, endorsement pending, committee decisions, clarifications, date range) and **Sensitive exports** (consolidated application export; referee recommendation content). Apply filters, then **Excel**, **CSV**, **PDF** or print. The preview may show only the first rows; exports contain all rows in your scope.

**Every export is logged** with your name, the report, format, filters, row count and address. Sensitive exports are also written to the audit trail. Handle downloaded files under the Institute's data-protection rules.

### 6.2 Email delivery log

**Email delivery log** lists every email the portal sent or tried to send, with status (Queued, Sent, Failed), recipient, template, linked application, attempts and any error. Filter by status, template key or recipient. A failed referee invitation also alerts the advertisement's administrators by email and notification.

### 6.3 Audit trail

**Audit trail** (sensitive) has four views: **Audit trail** (every significant action, with actor, record, address and before/after values; filter by action, actor type User / Referee / System and dates), **Email delivery**, **Document access** (every view or download of documents, application PDFs, receipts, scrutiny reports and referee recommendations), and **Exports**. Each application also has its own **History** and **Audit** tabs.

---

## 7. Status glossary (staff view)

| Field | Values |
|---|---|
| Application status | Draft · Submitted · Reopened · Withdrawn |
| Scrutiny status | Submitted · Received · Assigned · In progress · Clarification required · Clarification received · Completed (a stage report is in) · Under review · Decided |
| Fee status | Not submitted · Pending verification · Verified · Rejected · Exempt |
| Documents | Pending · Verified · Deficient |
| Referee status | Not started · Pending · Partial · Complete · Exception |
| Endorsement | Not applicable · Pending · Submitted · Verified · Rejected |
| Scrutiny outcome | Recommended · Incomplete · Not recommended |
| Committee status | Pending · Recommended · Not recommended · Further review |

## 8. Confidentiality

- Referee recommendations are never shown to applicants. Inside the portal they can be read only by staff holding the confidential-recommendations permission (by default Recruitment Administrators for their advertisements, and Screening Committee members for applications forwarded to their committee) and the Super Administrator.
- Every opening or download of a recommendation, document, application PDF or report is logged, and visible on **Document access**.
- Exports of recommendation content need a separate permission and are always logged.
- Staff cannot read the referee recommendations on their own application, and only the Super Administrator can open their own application from the staff side.

## 9. Troubleshooting

**A candidate did not get the verification email.** Check the **Email delivery log** for their address. Ask them to use **Resend email** on their dashboard.

**A referee invitation bounced or shows Delivery failed.** Confirm the correct address with the candidate, then **Issue replacement** with the **Corrected email**.

**A referee missed the deadline.** **Extend deadline**; the same link works again. Then **Send reminder** if needed.

**An email was never sent.** Check that the template is **Active** under **Email templates**, then check the **Email delivery log**.

**"The selected user does not hold the role required at stage …".** Give the user that role on **Users**, or pick another officer.

**"This user reported on an earlier stage …".** Assign a different officer, or tick **Same reviewer allowed** for that stage if the Institute permits.

**Forwarding is refused.** Read the message: the application is not recommended at scrutiny, is awaiting referees, or is outside the committee's scope.

**I cannot publish.** Open the **Status** tab and fix each listed problem.

**I cannot see an application.** Recruitment Administrators see only advertisements they are assigned to on the **People** tab.

**A candidate needs to correct a submitted application.** Use **Reopen for correction**; it is the only way to change submitted data.
