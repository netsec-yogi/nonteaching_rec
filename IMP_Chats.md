The portal is built and running at http://localhost:8010, with outgoing mail caught at http://localhost:8030. The 55 backend tests pass (546 assertions) and the frontend type-checks and builds cleanly. I haven't seen any page in a browser, because none was available here. The frontend was checked by type-checking and by calling the API directly, so a visual pass is the first thing to do.

Start, demo and test

Start it: ./dev.sh start (PHP and MySQL run in Docker).
Demo accounts: password for all is Password@123:
superadmin@iiitm.ac.in
recruitment@iiitm.ac.in
scrutiny1@iiitm.ac.in
committee1@iiitm.ac.in
applicant@example.com (submitted; one referee has responded)
applicant2@example.com (draft)
Referee links: the demo links are in Mailpit, so you can try the referee form yourself. Use the most recent emails; older ones carry links that no longer work.
Tests: ./dev.sh test.
What's in it

Application form: every numbered section of the paper form, from DD/NEFT through the declarations, Section 32 and the page-5 checklist, is a wizard step with the original numbering. Drafts save and resume, and the review screen lists anything still missing before final submission.
On submission: the application gets a reference number and a frozen snapshot. A PDF is stored and emailed as a confirmation. Three separate referee invitations go out by email.
Referees:
Each link is unique, random and hashed, and expires.
The form shows only the applicant's name, the post and the advertisement number.
A link can be used for one submission only.
Each recommendation gets a PDF.
Administrators can resend, remind, extend, replace and revoke invitations, with automatic reminders and every action logged.
Email delivery status is shown separately from recommendation status.
Applicants never see recommendation content.
Staff side: configurable checklists and workflow stages. A person who reported at one stage cannot approve the same application at the next. Clarification requests never change the submitted application. The Screening Committee can evaluate individually or decide collectively.
Reports and records: 20 reports with Excel, CSV and PDF export, with sensitive exports logged. There is a full audit trail and verified nightly backups.
Configuration: advertisements, posts, fees, eligibility, document requirements, forms, email templates and permissions are all edited in the admin screens. None of them is written into the code.
Spec note: the spec you pasted was cut off partway through Section 18 (acceptance criteria). I built the acceptance tests from the parts that came through, and from Sections 1–17.

Bugs found and fixed: testing turned up several real bugs, all now fixed. Two would have broken the app outright:

every permission check failed with a server error;
conditional "give details" fields were never actually required.
The others were empty backups, broken PDF page breaks and a crashing email job.

Not done or limited:

"Online" payment has no payment gateway. It records a transaction reference for staff to verify, the same as NEFT.
"Delivery failed" only catches the mail server refusing a message. Bounces that come back later aren't detected.
Retention: records are never deleted automatically when the retention period ends. Deleting them is left as a documented manual step.
Unused settings: three system-wide defaults are stored but don't affect anything yet: the referee deadline days, the upload size limit and the retention years.
Referees get no confirmation email after they submit.
Version control: the project isn't in a git repository yet. I didn't create one or commit anything; say if you want me to.
Before go-live: run php artisan recruitment:preflight --production. Have someone approve the privacy notice and declaration wording under Settings. These technical controls don't by themselves establish legal compliance.

Everything is in /home/hp/Documents/projects/nonteaching_rec_iiitm. Start with:

README.md
docs/DEPLOYMENT.md (server setup, HTTPS, queue worker and scheduler)
docs/OPERATIONS.md (environment settings, backups and recovery)
the user manuals in docs/manuals/, one each for applicants, referees, administrators, scrutiny officers and the committee